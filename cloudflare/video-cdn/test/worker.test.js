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

const files = {
  [`${PREFIX}/playlist.m3u8`]: '#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\n480p.m3u8\n',
  [`${PREFIX}/480p.m3u8`]: '#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI="https://api.example/videos/12/key",IV=0x1\n#EXTINF:6.0,\n480p_00000.ts\n#EXT-X-ENDLIST\n',
  [`${PREFIX}/480p_00000.ts`]: 'TS-BYTES',
  [`${PREFIX}/poster.jpg`]: 'JPG-BYTES',
}
let bucketReads = 0
const env = {
  STREAM_SECRET: SECRET,
  ALLOWED_ORIGINS: ORIGIN,
  BUCKET: {
    get: async (key) => {
      bucketReads++
      return key in files ? { body: files[key], httpEtag: '"e"', size: files[key].length } : null
    },
  },
}
const ctx = { waitUntil: (p) => p }

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
  const master = await call(`/${PREFIX}/playlist.m3u8?token=${token}`)
  assert.equal(master.status, 200)
  assert.equal(master.headers.get('Access-Control-Allow-Origin'), ORIGIN)
  assert.match(await master.text(), new RegExp(`^480p\\.m3u8\\?token=${token.replace(/\./g, '\\.')}$`, 'm'))

  const variant = await (await call(`/${PREFIX}/480p.m3u8?token=${token}`)).text()
  assert.match(variant, /URI="https:\/\/api\.example\/videos\/12\/key\?token=/)
  assert.match(variant, /^480p_00000\.ts\?token=/m)
})

test('segment: cần token đúng IP, cache dùng chung không kèm token', async () => {
  const token = await getToken()
  assert.equal((await call(`/${PREFIX}/480p_00000.ts`)).status, 403)
  assert.equal((await call(`/${PREFIX}/480p_00000.ts?token=${token}`, { ip: '198.51.100.1' })).status, 403)

  const before = bucketReads
  const first = await call(`/${PREFIX}/480p_00000.ts?token=${token}`)
  assert.equal(first.status, 200)
  assert.equal(await first.text(), 'TS-BYTES')
  // Cùng dải /24, token khác: vẫn hợp lệ và lấy từ cache.
  const second = await call(`/${PREFIX}/480p_00000.ts?token=${await getToken({ ip: '203.0.113.200' })}`, { ip: '203.0.113.99' })
  assert.equal(second.status, 200)
  assert.equal(bucketReads, before + 1)
})

test('token hết hạn / sai thư mục / path lạ', async () => {
  const now = Math.floor(Date.now() / 1000)
  const old = await mintStreamToken(SECRET, PREFIX, '203.0.113.0/24', -5, now)
  assert.equal((await call(`/${PREFIX}/480p_00000.ts?token=${old.token}`)).status, 403)
  assert.equal(await verifyStreamToken(SECRET, old.token, PREFIX, '203.0.113.0/24', now - 100), true)

  const token = await getToken()
  assert.equal((await call(`/hls/99-other/480p_00000.ts?token=${token}`)).status, 403)
  assert.equal((await call(`/${PREFIX}/../secret.key?token=${token}`)).status, 404)
  assert.equal((await call(`/${PREFIX}/secret.key?token=${token}`)).status, 404)
})

test('addTokenToPlaylist giữ nguyên dòng comment', () => {
  const out = addTokenToPlaylist('#EXTM3U\n#EXTINF:6,\na.ts\n', 't')
  assert.equal(out, '#EXTM3U\n#EXTINF:6,\na.ts?token=t\n')
})

test('poster: không cần token nhưng phải đúng Referer/Origin', async () => {
  const ok = await call(`/${PREFIX}/poster.jpg`, { origin: null, headers: { Referer: `${ORIGIN}/` } })
  assert.equal(ok.status, 200)
  assert.equal(ok.headers.get('Content-Type'), 'image/jpeg')
  assert.equal(await ok.text(), 'JPG-BYTES')
  assert.equal((await call(`/${PREFIX}/poster.jpg`, { origin: null })).status, 403)
  assert.equal((await call(`/${PREFIX}/poster.jpg`, { origin: null, headers: { Referer: 'https://evil.example/' } })).status, 403)
  assert.equal((await call(`/hls/99-other/poster.jpg`, { origin: null, headers: { Referer: `${ORIGIN}/` } })).status, 404)
})
