/**
 * Phát HLS từ R2 cho web FFmpeg Stream.
 *
 *   GET /hls/{dir}/token?grant=…[&native=1]  đổi grant (Laravel ký) lấy stream token gắn dải IP
 *   GET /hls/{dir}/{file}.m3u8?token=…       playlist, đã gắn sẵn token vào mọi URI bên trong
 *   GET /hls/{dir}/{file}.ts?token=…         segment (đã mã hóa AES-128), cache ở edge
 *   GET /hls/{dir}/poster.jpg                ảnh bìa, KHÔNG cần token (thẻ <img> không gắn được),
 *                                            chỉ kiểm tra Referer; {dir} có phần ngẫu nhiên nên không đoán được
 *
 * Khóa AES không nằm trên R2: URI khóa trong playlist trỏ về Laravel
 * (VideoKeyController), cũng đòi đúng stream token này.
 */
import { ipNetwork, mintStreamToken, verifyGrant, verifyStreamToken } from './token.js'

const PATH_PATTERN = /^\/(hls\/[A-Za-z0-9_-]+)\/([A-Za-z0-9_-]+(?:\.m3u8|\.ts)|token|poster\.jpg)$/

const CONTENT_TYPES = {
  m3u8: 'application/vnd.apple.mpegurl',
  ts: 'video/mp2t',
  jpg: 'image/jpeg',
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

    if (file === 'poster.jpg') {
      const poster = await fromCacheOrBucket(new Request(`${url.origin}/${prefix}/${file}`), env, ctx, `${prefix}/${file}`, 'jpg')
      if (!poster) return deny(404, allowedOrigin)
      const headers = new Headers(poster.headers)
      headers.set('Cache-Control', 'public, max-age=86400')
      return new Response(request.method === 'HEAD' ? null : poster.body, { headers })
    }

    const token = url.searchParams.get('token')
    if (!(await verifyStreamToken(env.STREAM_SECRET, token, prefix, ipnet, now))) {
      return deny(403, allowedOrigin)
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
