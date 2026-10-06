import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { test } from 'node:test'
import worker, { addTokenToPlaylist } from '../src/index.js'
import { ipNetwork, mintStreamToken, verifyStreamToken } from '../src/token.js'

const SECRET = 'test-secret-test-secret-test-secret-1234'
const PREFIX = 'hls/12-AbCdEfGhIjKlMnOpQrStUvWx'
const ORIGIN = 'https://app.example.com'
const PHP_ROOT = new URL('../../../', import.meta.url).pathname

// ---- Giả lập runtime Cloudflare: caches.default + R2 binding ----
const cacheStore = new Map()
globalThis.caches = {
  default: {
    match: async (req) => cacheStore.get(req.url)?.clone(),
    put: async (req, res) => void cacheStore.set(req.url, res),
  },
}

const KEY_ID = 'K3yIdK3yIdK3yIdK3yIdK3yIdK3yIdK3yIdK3yId'
const files = {
  [`${PREFIX}/Mstr.m3u8`]: '#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nVar1.m3u8\n',
  [`${PREFIX}/Var1.m3u8`]: `#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI="${KEY_ID}.key",IV=0x1\n#EXTINF:6.0,\nSeg1.ts\n#EXT-X-ENDLIST\n`,
  [`${PREFIX}/Seg1.ts`]: 'TS-BYTES',
}
let bucketReads = 0
const env = {
  STREAM_SECRET: SECRET,
  ALLOWED_ORIGINS: ORIGIN,
  BUCKET: {
    head: async (key) => (key in files ? { size: files[key].length } : null),
    get: async (key) => {
      bucketReads++
      return key in files ? { body: files[key], httpEtag: '"e"', size: files[key].length } : null
    },
  },
}
env.API_ORIGIN = 'https://api.example'
const ctx = { waitUntil: (p) => p }

// Laravel /videos/keys/{keyId}: chỉ trả khóa khi Worker chuyển đúng token sang.
const realFetch = globalThis.fetch
let keyRequests = []
globalThis.fetch = async (url, init) => {
  const u = new URL(String(url))
  if (u.origin !== 'https://api.example') return realFetch(url, init)
  keyRequests.push(u)
  return u.pathname === `/videos/keys/${KEY_ID}` && u.searchParams.get('token')
    ? new Response(new Uint8Array(16).fill(7))
    : new Response(null, { status: 404 })
}

function call(path, { origin = ORIGIN, ip = '203.0.113.7', headers = {} } = {}) {
  const h = new Headers({ 'CF-Connecting-IP': ip, ...headers })
  if (origin) h.set('Origin', origin)
  return worker.fetch(new Request(`https://cdn.test${path}`, { headers: h }), env, ctx)
}

/** Grant do chính StreamToken.php ký, để chắc hai bên khớp định dạng. */
function phpGrant(ttl = 120) {
  const code = `require 'vendor/autoload.php'; echo json_encode((new App\\Video\\StreamToken('${SECRET}'))->grant('${PREFIX}', ${ttl}));`
  return JSON.parse(execFileSync('php', ['-r', code], { cwd: PHP_ROOT })).grant
}

/** Ảnh bìa mã hóa bằng chính PosterVault.php. */
function phpEncryptPoster(jpeg) {
  const code = `require 'vendor/autoload.php'; echo base64_encode((new App\\Video\\PosterVault('${SECRET}'))->encrypt($argv[1], '${PREFIX}'));`
  return Buffer.from(execFileSync('php', ['-r', code, jpeg], { cwd: PHP_ROOT }).toString(), 'base64')
}

/** Chữ ký URL ảnh bìa do StreamToken.php cấp. */
function phpSignPoster(path) {
  const code = `require 'vendor/autoload.php'; echo json_encode((new App\\Video\\StreamToken('${SECRET}'))->signPoster($argv[1]));`
  return JSON.parse(execFileSync('php', ['-r', code, path], { cwd: PHP_ROOT }))
}

function phpVerifyStream(token) {
  const code = `require 'vendor/autoload.php'; echo (new App\\Video\\StreamToken('${SECRET}'))->verifyStream($argv[1], '${PREFIX}') ? 'yes' : 'no';`
  return execFileSync('php', ['-r', code, token], { cwd: PHP_ROOT }).toString() === 'yes'
}

async function getToken(opts) {
  const res = await call(`/${PREFIX}/token?grant=${phpGrant()}`, opts)
  assert.equal(res.status, 200)
  return (await res.json()).token
}

test('ipNetwork', () => {
  assert.equal(ipNetwork('203.0.113.7', 24, 64), '203.0.113.0/24')
  assert.equal(ipNetwork('203.0.113.7', 0, 64), '')
  assert.equal(ipNetwork('2001:db8:1:2:3:4:5:6', 24, 64), '2001:db8:1:2:0:0:0:0/64')
  assert.equal(ipNetwork('2001:db8::1', 24, 48), '2001:db8:0:0:0:0:0:0/48')
  assert.equal(ipNetwork('::ffff:1.2.3.4', 24, 128), '0:0:0:0:0:ffff:102:304/128')
  assert.equal(ipNetwork('garbage', 24, 64), '')
})

test('grant của PHP đổi được token, token của Worker được PHP chấp nhận', async () => {
  const token = await getToken()
  assert.ok(phpVerifyStream(token))
  assert.ok(!phpVerifyStream(token.replace(/.$/, (c) => (c === 'A' ? 'B' : 'A'))))
})

test('grant sai / hết hạn / sai video bị từ chối', async () => {
  assert.equal((await call(`/${PREFIX}/token?grant=123.abc`)).status, 403)
  const expired = phpGrant(-10)
  assert.equal((await call(`/${PREFIX}/token?grant=${expired}`)).status, 403)
  assert.equal((await call(`/hls/99-other/token?grant=${phpGrant()}`)).status, 403)
})

test('chặn Origin lạ, chấp nhận Referer khi thiếu Origin (Safari native)', async () => {
  assert.equal((await call(`/${PREFIX}/token?grant=${phpGrant()}`, { origin: 'https://evil.example' })).status, 403)
  assert.equal((await call(`/${PREFIX}/token?grant=${phpGrant()}`, { origin: null })).status, 403)
  const res = await call(`/${PREFIX}/token?grant=${phpGrant()}`, { origin: null, headers: { Referer: `${ORIGIN}/videos/12` } })
  assert.equal(res.status, 200)
})

test('playlist được gắn token vào variant, segment và khóa', async () => {
  const token = await getToken()
  const master = await call(`/${PREFIX}/Mstr.m3u8?token=${token}`)
  assert.equal(master.status, 200)
  assert.equal(master.headers.get('Access-Control-Allow-Origin'), ORIGIN)
  assert.match(await master.text(), new RegExp(`^Var1\\.m3u8\\?token=${token.replace(/\./g, '\\.')}$`, 'm'))

  const variant = await (await call(`/${PREFIX}/Var1.m3u8?token=${token}`)).text()
  assert.match(variant, new RegExp(`URI="${KEY_ID}\\.key\\?token=`))
  assert.match(variant, /^Seg1\.ts\?token=/m)
})

test('khóa: Worker kiểm tra token + IP rồi lấy từ Laravel kèm token', async () => {
  const token = await getToken()
  keyRequests = []
  assert.equal((await call(`/${PREFIX}/${KEY_ID}.key`)).status, 403)
  assert.equal((await call(`/${PREFIX}/${KEY_ID}.key?token=${token}`, { ip: '198.51.100.1' })).status, 403)
  assert.equal(keyRequests.length, 0)

  const ok = await call(`/${PREFIX}/${KEY_ID}.key?token=${token}`)
  assert.equal(ok.status, 200)
  assert.equal(ok.headers.get('Cache-Control'), 'private, no-store')
  assert.equal(new Uint8Array(await ok.arrayBuffer())[0], 7)
  assert.equal(keyRequests.at(-1).searchParams.get('token'), token)

  assert.equal((await call(`/${PREFIX}/OtherKey.key?token=${token}`)).status, 404)
})

test('segment: cần token đúng IP, cache dùng chung không kèm token', async () => {
  const token = await getToken()
  assert.equal((await call(`/${PREFIX}/Seg1.ts`)).status, 403)
  assert.equal((await call(`/${PREFIX}/Seg1.ts?token=${token}`, { ip: '198.51.100.1' })).status, 403)

  const before = bucketReads
  const first = await call(`/${PREFIX}/Seg1.ts?token=${token}`)
  assert.equal(first.status, 200)
  assert.equal(await first.text(), 'TS-BYTES')
  // Cùng dải /24, token khác: vẫn hợp lệ và lấy từ cache.
  const second = await call(`/${PREFIX}/Seg1.ts?token=${await getToken({ ip: '203.0.113.200' })}`, { ip: '203.0.113.99' })
  assert.equal(second.status, 200)
  assert.equal(bucketReads, before + 1)
})

test('token hết hạn / sai thư mục / path lạ', async () => {
  const now = Math.floor(Date.now() / 1000)
  const old = await mintStreamToken(SECRET, PREFIX, '203.0.113.0/24', -5, now)
  assert.equal((await call(`/${PREFIX}/Seg1.ts?token=${old.token}`)).status, 403)
  assert.equal(await verifyStreamToken(SECRET, old.token, PREFIX, '203.0.113.0/24', now - 100), true)

  const token = await getToken()
  assert.equal((await call(`/hls/99-other/Seg1.ts?token=${token}`)).status, 403)
  assert.equal((await call(`/${PREFIX}/../secret.key?token=${token}`)).status, 404)
  assert.equal((await call(`/${PREFIX}/poster.jpg?token=${token}`)).status, 404)
})

test('addTokenToPlaylist giữ nguyên dòng comment', () => {
  const out = addTokenToPlaylist('#EXTM3U\n#EXTINF:6,\na.ts\n', 't')
  assert.equal(out, '#EXTM3U\n#EXTINF:6,\na.ts?token=t\n')
})

test('ảnh bìa: cần URL do Laravel ký; R2 lưu bản mã hóa (PosterVault.php), Worker giải mã', async () => {
  const jpeg = 'JPEG-\u00ff-BYTES'
  const path = `${PREFIX}/Pstr.img`
  files[path] = phpEncryptPoster(jpeg)
  assert.notEqual(files[path].toString(), jpeg)
  const { exp, sig } = phpSignPoster(path)
  const referer = { origin: null, headers: { Referer: `${ORIGIN}/` } }

  const ok = await call(`/${path}?exp=${exp}&sig=${sig}`, referer)
  assert.equal(ok.status, 200)
  assert.equal(ok.headers.get('Content-Type'), 'image/jpeg')
  assert.match(ok.headers.get('Cache-Control'), /^private, max-age=\d+$/)
  assert.equal(Buffer.from(await ok.arrayBuffer()).toString(), Buffer.from(jpeg).toString())

  // Không có / sai / hết hạn chữ ký (vd. video private, video đã xóa): chặn, kể cả khi ảnh còn trong cache.
  assert.equal((await call(`/${path}`, referer)).status, 403)
  assert.equal((await call(`/${path}?exp=${exp}&sig=${sig.slice(0, -2)}AA`, referer)).status, 403)
  assert.equal((await call(`/${path}?exp=${exp + 3600}&sig=${sig}`, referer)).status, 403)
  const expired = Math.floor(Date.now() / 1000) - 10
  assert.equal((await call(`/${path}?exp=${expired}&sig=${sig}`, referer)).status, 403)
  // Chữ ký của ảnh này không dùng được cho file khác.
  files[`${PREFIX}/Othr.img`] = files[path]
  assert.equal((await call(`/${PREFIX}/Othr.img?exp=${exp}&sig=${sig}`, referer)).status, 403)
  // Video bị xóa (file không còn trên R2) nhưng ảnh vẫn nằm trong cache edge: chặn ngay.
  const cachedCopy = files[path]
  delete files[path]
  assert.equal((await call(`/${path}?exp=${exp}&sig=${sig}`, referer)).status, 404)
  files[path] = cachedCopy
  // Đúng chữ ký nhưng sai Referer: vẫn chặn.
  assert.equal((await call(`/${path}?exp=${exp}&sig=${sig}`, { origin: null, headers: { Referer: 'https://evil.example/' } })).status, 403)
})
