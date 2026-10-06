/**
 * Phát HLS từ R2 cho web FFmpeg Stream.
 *
 *   GET /hls/{dir}/token?grant=…[&native=1]  đổi grant (Laravel ký) lấy stream token gắn dải IP
 *   GET /hls/{dir}/{file}.m3u8?token=…       playlist, đã gắn sẵn token vào mọi URI bên trong
 *   GET /hls/{dir}/{name}.ts?token=…         segment (đã mã hóa AES-128), cache ở edge
 *   GET /hls/{dir}/{keyId}.key?token=…       khóa AES của một mức: kiểm tra token rồi lấy từ Laravel
 *                                            (VideoKeyController) — khóa KHÔNG nằm trên R2
 *   GET /hls/{dir}/{name}.img?exp=…&sig=…    ảnh bìa: URL do Laravel ký (chỉ người xem được video mới
 *                                            có), R2 lưu bản mã hóa, Worker kiểm tra chữ ký rồi giải mã
 *                                            trả JPEG. Không dùng stream token vì thẻ <img> không gắn được
 *
 * Mọi tên thư mục / file trên R2 đều ngẫu nhiên, playlist không chứa URL API
 * hay video id: ai vào được R2 chỉ thấy file mã hóa không rõ của video nào.
 */
import { decryptPoster } from './poster.js'
import { ipNetwork, mintStreamToken, verifyGrant, verifyPosterSignature, verifyStreamToken } from './token.js'

const PATH_PATTERN = /^\/(hls\/[A-Za-z0-9_-]+)\/([A-Za-z0-9_-]+\.(?:m3u8|ts|key|img)|token)$/

const CONTENT_TYPES = {
  m3u8: 'application/vnd.apple.mpegurl',
  ts: 'video/mp2t',
  img: 'application/octet-stream',
}

export default {
  async fetch(request, env, ctx) {
    const allowedOrigin = matchAllowedOrigin(request, env)

    if (request.method === 'OPTIONS') {
      return allowedOrigin ? new Response(null, { status: 204, headers: corsHeaders(allowedOrigin, true) }) : deny(403)
    }
    if (request.method !== 'GET' && request.method !== 'HEAD') return deny(405)

    // Chặn trang khác nhúng player / gọi thẳng từ trình duyệt ở domain lạ.
    // Origin/Referer giả được bằng curl, việc chặn thật nằm ở token bên dưới.
    if (!allowedOrigin) return deny(403)

    const url = new URL(request.url)
    const match = PATH_PATTERN.exec(url.pathname)
    if (!match) return deny(404, allowedOrigin)

    const [, prefix, file] = match
    const now = Math.floor(Date.now() / 1000)
    const ipnet = ipNetwork(
      request.headers.get('CF-Connecting-IP'),
      Number(env.IP_PREFIX_V4 ?? 24),
      Number(env.IP_PREFIX_V6 ?? 64),
    )

    if (file === 'token') {
      if (!(await verifyGrant(env.STREAM_SECRET, url.searchParams.get('grant'), prefix, now))) {
        return deny(403, allowedOrigin)
      }
      // Safari/iOS cũ phát HLS native, không gia hạn token giữa chừng được: cấp hạn dài hơn.
      const ttl = Number(url.searchParams.get('native') === '1' ? env.NATIVE_TOKEN_TTL ?? 14400 : env.TOKEN_TTL ?? 900)
      const body = await mintStreamToken(env.STREAM_SECRET, prefix, ipnet, ttl, now)
      return new Response(JSON.stringify(body), {
        headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store', ...corsHeaders(allowedOrigin) },
      })
    }

    if (file.endsWith('.img')) {
      const exp = url.searchParams.get('exp')
      if (!(await verifyPosterSignature(env.STREAM_SECRET, `${prefix}/${file}`, exp, url.searchParams.get('sig'), now))) {
        return deny(403, allowedOrigin)
      }
      // Ảnh bìa nằm trong cache edge tới 1 năm: hỏi R2 trước để video đã xóa
      // bị chặn ngay (1 lần head ~ 1 request Class B, không cần purge cache).
      if (!(await env.BUCKET.head(`${prefix}/${file}`))) return deny(404, allowedOrigin)
      const stored = await fromCacheOrBucket(new Request(`${url.origin}/${prefix}/${file}`), env, ctx, `${prefix}/${file}`, 'img')
      const jpeg = stored && (await decryptPoster(env.STREAM_SECRET, prefix, await stored.arrayBuffer()))
      if (!jpeg) return deny(404, allowedOrigin)
      return new Response(request.method === 'HEAD' ? null : jpeg, {
        // Trình duyệt giữ ảnh tới khi URL ký hết hạn; không để proxy dùng chung lưu bản giải mã.
        headers: { 'Content-Type': 'image/jpeg', 'Cache-Control': `private, max-age=${Math.max(0, Number(exp) - now)}` },
      })
    }

    const token = url.searchParams.get('token')
    if (!(await verifyStreamToken(env.STREAM_SECRET, token, prefix, ipnet, now))) {
      return deny(403, allowedOrigin)
    }

    if (file.endsWith('.key')) {
      return proxyKey(env, file.slice(0, -'.key'.length), token, allowedOrigin)
    }

    const key = `${prefix}/${file}`
    const extension = file.split('.').pop()

    if (extension === 'ts' && request.headers.has('Range')) {
      return serveRange(request, env, key, allowedOrigin)
    }

    const cached = await fromCacheOrBucket(new Request(`${url.origin}/${key}`), env, ctx, key, extension)
    if (!cached) return deny(404, allowedOrigin)

    if (extension === 'm3u8') {
      // Playlist chứa token của người xem này: không cho ai cache bản đã sửa.
      return new Response(addTokenToPlaylist(await cached.text(), token), {
        headers: { 'Content-Type': CONTENT_TYPES.m3u8, 'Cache-Control': 'private, no-store', ...corsHeaders(allowedOrigin) },
      })
    }

    const headers = new Headers(cached.headers)
    headers.set('Cache-Control', 'private, max-age=86400')
    for (const [name, value] of Object.entries(corsHeaders(allowedOrigin))) headers.set(name, value)
    return new Response(request.method === 'HEAD' ? null : cached.body, { status: 200, headers })
  },
}

/**
 * File HLS của một lần encode không bao giờ đổi (mỗi lần encode lại ra thư
 * mục mới), nên cache ở edge vô thời hạn, khóa theo đường dẫn KHÔNG kèm token:
 * mọi người xem dùng chung một bản cache, R2 chỉ bị đọc lần đầu.
 */
async function fromCacheOrBucket(cacheKey, env, ctx, key, extension) {
  const cache = caches.default
  const hit = await cache.match(cacheKey)
  if (hit) return hit

  const object = await env.BUCKET.get(key)
  if (!object) return null

  const response = new Response(object.body, {
    headers: {
      'Content-Type': CONTENT_TYPES[extension],
      'Cache-Control': 'public, max-age=31536000, immutable',
      ETag: object.httpEtag,
    },
  })
  ctx.waitUntil(cache.put(cacheKey, response.clone()))
  return response
}

async function serveRange(request, env, key, allowedOrigin) {
  const object = await env.BUCKET.get(key, { range: request.headers })
  if (!object) return deny(404, allowedOrigin)

  const headers = new Headers({ 'Content-Type': CONTENT_TYPES.ts, 'Accept-Ranges': 'bytes', ...corsHeaders(allowedOrigin) })
  let status = 200
  if (object.range && 'offset' in object.range) {
    const { offset, length = object.size - offset } = object.range
    headers.set('Content-Range', `bytes ${offset}-${offset + length - 1}/${object.size}`)
    status = 206
  }
  return new Response(request.method === 'HEAD' ? null : object.body, { status, headers })
}

/**
 * Khóa AES chỉ nằm trong DB của Laravel. Worker đã kiểm tra token + dải IP;
 * Laravel kiểm tra lại token và khóa có đúng thuộc thư mục của token không.
 */
async function proxyKey(env, keyId, token, allowedOrigin) {
  const upstream = await fetch(`${String(env.API_ORIGIN).replace(/\/$/, '')}/videos/keys/${keyId}?token=${encodeURIComponent(token)}`, {
    cf: { cacheTtl: 0, cacheEverything: false },
  })
  if (!upstream.ok) return deny(upstream.status === 404 ? 404 : 403, allowedOrigin)
  return new Response(await upstream.arrayBuffer(), {
    headers: { 'Content-Type': 'application/octet-stream', 'Cache-Control': 'private, no-store', ...corsHeaders(allowedOrigin) },
  })
}

/** Gắn token vào mọi URI trong playlist (variant, segment, khóa AES). */
export function addTokenToPlaylist(text, token) {
  const withToken = (uri) => {
    if (/^https?:\/\//i.test(uri)) {
      const absolute = new URL(uri)
      absolute.searchParams.set('token', token)
      return absolute.toString()
    }
    return `${uri}${uri.includes('?') ? '&' : '?'}token=${encodeURIComponent(token)}`
  }

  return text
    .split('\n')
    .map((line) => {
      const trimmed = line.trim()
      if (trimmed.startsWith('#EXT-X-KEY')) return line.replace(/URI="([^"]+)"/, (_, uri) => `URI="${withToken(uri)}"`)
      if (!trimmed || trimmed.startsWith('#')) return line
      return withToken(trimmed)
    })
    .join('\n')
}

/**
 * XHR/fetch cross-origin (hls.js) luôn gửi Origin. Thẻ <video> phát HLS native
 * (Safari) không gửi Origin nhưng có Referer, nên chấp nhận Referer khi thiếu Origin.
 */
function matchAllowedOrigin(request, env) {
  const allowed = String(env.ALLOWED_ORIGINS ?? '')
    .split(',')
    .map((origin) => origin.trim())
    .filter(Boolean)

  let origin = request.headers.get('Origin')
  if (!origin) {
    try {
      origin = new URL(request.headers.get('Referer') ?? '').origin
    } catch {
      return null
    }
  }
  return allowed.includes(origin) ? origin : null
}

function corsHeaders(origin, preflight = false) {
  const headers = { 'Access-Control-Allow-Origin': origin, Vary: 'Origin' }
  if (preflight) {
    headers['Access-Control-Allow-Methods'] = 'GET, HEAD, OPTIONS'
    headers['Access-Control-Allow-Headers'] = 'Range'
    headers['Access-Control-Max-Age'] = '86400'
  }
  return headers
}

function deny(status, allowedOrigin) {
  return new Response(null, {
    status,
    headers: { 'Cache-Control': 'no-store', ...(allowedOrigin ? corsHeaders(allowedOrigin) : {}) },
  })
}
