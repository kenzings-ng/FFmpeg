/**
 * Ảnh bìa được lưu trên R2 ở dạng mã hóa. Định dạng PHẢI khớp với
 * app/Video/PosterVault.php:
 *
 *   key  = HMAC-SHA256(secret, "poster\n{prefix}")   (AES-256)
 *   file = iv (12 byte) || ciphertext || tag (16 byte)   (AES-GCM)
 */
const encoder = new TextEncoder()

async function posterKey(secret, prefix) {
  const hmacKey = await crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
  const raw = await crypto.subtle.sign('HMAC', hmacKey, encoder.encode(`poster\n${prefix}`))
  return crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['decrypt'])
}

/** @returns {Promise<ArrayBuffer|null>} JPEG, hoặc null nếu file hỏng / sai khóa. */
export async function decryptPoster(secret, prefix, payload) {
  const bytes = new Uint8Array(payload)
  if (bytes.length < 28) return null
  try {
    return await crypto.subtle.decrypt({ name: 'AES-GCM', iv: bytes.slice(0, 12) }, await posterKey(secret, prefix), bytes.slice(12))
  } catch {
    return null
  }
}
