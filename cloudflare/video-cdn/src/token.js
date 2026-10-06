/**
 * Token phát video. Định dạng PHẢI khớp với app/Video/StreamToken.php:
 *
 *   grant  = "{exp}.{sig}"          sig = HMAC("grant\n{prefix}\n{exp}")
 *   stream = "{exp}.{net}.{sig}"    sig = HMAC("stream\n{prefix}\n{exp}\n{ipnet}")
 *   ảnh bìa = "{path}?exp={exp}&sig={sig}"   sig = HMAC("poster\n{path}\n{exp}")
 *
 * net = base64url(ipnet); sig = base64url(HMAC-SHA256(secret, message)), không padding.
 */

const encoder = new TextEncoder()
const keyCache = new Map()

function hmacKey(secret) {
  if (!keyCache.has(secret)) {
    keyCache.set(
      secret,
      crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign', 'verify']),
    )
  }
  return keyCache.get(secret)
}

export function base64UrlEncode(bytes) {
  let binary = ''
  for (const byte of new Uint8Array(bytes)) binary += String.fromCharCode(byte)
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

export function base64UrlDecode(value) {
  if (!/^[A-Za-z0-9_-]*$/.test(value)) return null
  try {
    const binary = atob(value.replace(/-/g, '+').replace(/_/g, '/'))
    return Uint8Array.from(binary, (char) => char.charCodeAt(0))
  } catch {
    return null
  }
}

async function sign(secret, parts) {
  const signature = await crypto.subtle.sign('HMAC', await hmacKey(secret), encoder.encode(parts.join('\n')))
  return base64UrlEncode(signature)
}

/** So sánh chữ ký bằng crypto.subtle.verify (thời gian hằng). */
async function verify(secret, signature, parts) {
  const bytes = base64UrlDecode(signature)
  if (!bytes) return false
  return crypto.subtle.verify('HMAC', await hmacKey(secret), bytes, encoder.encode(parts.join('\n')))
}

function parseExp(value, now) {
  if (!/^\d+$/.test(value)) return null
  const exp = Number(value)
  return exp >= now ? exp : null
}

export async function verifyGrant(secret, grant, prefix, now) {
  const parts = String(grant ?? '').split('.')
  if (parts.length !== 2 || parseExp(parts[0], now) === null) return false
  return verify(secret, parts[1], ['grant', prefix, parts[0]])
}

export async function mintStreamToken(secret, prefix, ipnet, ttl, now) {
  const exp = String(now + ttl)
  const net = base64UrlEncode(encoder.encode(ipnet))
  return { token: `${exp}.${net}.${await sign(secret, ['stream', prefix, exp, ipnet])}`, expires_at: Number(exp) }
}

/** ipnet: dải IP của request hiện tại; token phải được cấp cho đúng dải đó. */
export async function verifyStreamToken(secret, token, prefix, ipnet, now) {
  const parts = String(token ?? '').split('.')
  if (parts.length !== 3 || parseExp(parts[0], now) === null) return false
  const netBytes = base64UrlDecode(parts[1])
  if (!netBytes || new TextDecoder().decode(netBytes) !== ipnet) return false
  return verify(secret, parts[2], ['stream', prefix, parts[0], ipnet])
}

/** URL ảnh bìa do Laravel ký (StreamToken::signPoster). path không có '/' ở đầu. */
export async function verifyPosterSignature(secret, path, exp, sig, now) {
  if (parseExp(String(exp ?? ''), now) === null) return false
  return verify(secret, String(sig ?? ''), ['poster', path, String(exp)])
}

/**
 * Dải mạng chứa ip, vd. ("1.2.3.4", 24) -> "1.2.3.0/24". bits = 0 nghĩa là
 * không gắn IP (trả chuỗi rỗng). IPv6 trả dạng đầy đủ 8 nhóm hex.
 */
export function ipNetwork(ip, v4Bits, v6Bits) {
  if (!ip) return ''
  if (ip.includes(':')) {
    if (!v6Bits) return ''
    const groups = expandIPv6(ip)
    if (!groups) return ''
    return `${maskBits(groups, 16, v6Bits).map((g) => g.toString(16)).join(':')}/${v6Bits}`
  }
  if (!v4Bits) return ''
  const octets = ip.split('.').map(Number)
  if (octets.length !== 4 || octets.some((o) => !Number.isInteger(o) || o < 0 || o > 255)) return ''
  return `${maskBits(octets, 8, v4Bits).join('.')}/${v4Bits}`
}

function maskBits(values, width, bits) {
  return values.map((value, index) => {
    const keep = Math.max(0, Math.min(width, bits - index * width))
    const mask = keep === 0 ? 0 : ((2 ** width - 1) << (width - keep)) & (2 ** width - 1)
    return value & mask
  })
}

function expandIPv6(ip) {
  const address = ip.split('%')[0]
  const halves = address.split('::')
  if (halves.length > 2) return null
  const parse = (part) => (part ? part.split(':') : [])
  let head = parse(halves[0])
  let tail = halves.length === 2 ? parse(halves[1]) : []
  // IPv6 nhúng IPv4 ở cuối, vd. ::ffff:1.2.3.4
  const last = (tail.length ? tail : head).at(-1)
  if (last && last.includes('.')) {
    const o = last.split('.').map(Number)
    const embedded = [((o[0] << 8) | o[1]).toString(16), ((o[2] << 8) | o[3]).toString(16)]
    if (tail.length) tail = [...tail.slice(0, -1), ...embedded]
    else head = [...head.slice(0, -1), ...embedded]
  }
  const missing = 8 - head.length - tail.length
  if (halves.length === 1 ? missing !== 0 : missing < 0) return null
  const groups = [...head, ...Array(missing).fill('0'), ...tail].map((g) => parseInt(g, 16))
  return groups.every((g) => Number.isInteger(g) && g >= 0 && g <= 0xffff) ? groups : null
}
