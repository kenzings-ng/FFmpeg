# video-cdn: Stream HLS from Cloudflare R2

Cloudflare Worker fronting a private R2 bucket: validates stream tokens, caches segments at edge locations, and incurs zero egress bandwidth fees.

```
Player ──① query video { stream { grant … } } ──────────────▶ Laravel (GraphQL)
       ──② GET cdn/hls/{dir}/token?grant=…  ────────────────▶ Worker: verifies grant → token bound to IP subnet
       ──③ GET cdn/hls/{dir}/*.m3u8|*.ts?token=… ───────────▶ Worker: verifies token+IP → cache/R2
       ──④ GET cdn/hls/{dir}/{keyId}.key?token=… ───────────▶ Worker: verifies token+IP → Laravel /videos/keys/{keyId}
       ──⑤ <img src=cdn/hls/{dir}/{name}.img> ─────────────▶ Worker: verifies Referer → decrypts poster
```

On R2, only encrypted files with randomized filenames exist (no video IDs, resolutions, or API URLs).

Shared formats between Laravel and Worker (cross-tested):
- Stream token: `src/token.js` ⇄ `app/Video/StreamToken.php`
- Poster vault: `src/poster.js` ⇄ `app/Video/PosterVault.php`

## Initial Setup

### 1. R2 (Cloudflare dashboard)
1. **R2 Object Storage** → Enable R2.
2. **Create bucket** named `ffmpeg-videos`, Location hint: *Asia-Pacific*.
   Do **not** enable Public access / `r2.dev`.
3. **Manage R2 API Tokens** → *Create API token* → permissions **Object Read & Write**, restricted to `ffmpeg-videos`.
   Record *Access Key ID*, *Secret Access Key*, and endpoint `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`.

### 2. Laravel (`.env` in repo root)
```dotenv
VIDEO_HLS_DISK=local            # keep local until step 4
VIDEO_CDN_URL=https://cdn.example.com
VIDEO_STREAM_SECRET=            # openssl rand -base64 48
VIDEO_ALLOWED_ORIGINS=https://app.example.com
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=ffmpeg-videos
R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
```
Verify connection:
```bash
php artisan tinker --execute 'Storage::disk("r2")->put("ping.txt","ok"); echo Storage::disk("r2")->get("ping.txt"); Storage::disk("r2")->delete("ping.txt");'
```

### 3. Deploy Worker
```bash
cd cloudflare/video-cdn
cp wrangler.toml.example wrangler.toml   # configure domain, bucket, ALLOWED_ORIGINS, API_ORIGIN
npm install
npx wrangler login                 # or export CLOUDFLARE_API_TOKEN=… (Workers Scripts:Edit, R2:Edit, DNS)
npx wrangler secret put STREAM_SECRET   # paste exact VIDEO_STREAM_SECRET value
npx wrangler deploy
```
`wrangler deploy` provisions DNS + TLS certificates automatically for routes declared in `wrangler.toml`
(`wrangler.toml` contains live domains and is ignored by git; only `wrangler.toml.example` is committed).

### 4. Enable
```bash
# In .env: VIDEO_HLS_DISK=r2
php artisan queue:restart          # workers reload configuration
# then build + redeploy frontend
```
Only videos encoded **after** enabling will be stored on R2; existing videos remain on local storage.

### 5. Supervisor (requires sudo, one-time)
Worker timeout is increased to 3 hours for long videos:
```bash
sudo cp supervisor/laravel-worker.conf.example /etc/supervisor/conf.d/laravel-worker.conf   # update paths / user first
sudo supervisorctl reread && sudo supervisorctl update
```

## Post-deployment Verification
- Upload a video → files appear under bucket `hls/{random_string}/` with randomized filenames.
- Playable on web player; DevTools → Network: segments load from `ffmpeg-cdn…` with `?token=`.
- Copy a segment URL and open in a new tab (missing Origin) → **403**.
- Open from a different network (mobile data) with the same token → **403**; switching Wi-Fi ↔ mobile data during active web playback continues seamlessly.
- Watch past 15 minutes → video playback continues without interruption (player auto-renews stream tokens).

## Customization (`wrangler.toml` → `[vars]`)
| Variable | Default | Description |
|---|---|---|
| `API_ORIGIN` | Production API | Laravel backend where Worker fetches AES keys (`/videos/keys/{keyId}`) |
| `ALLOWED_ORIGINS` | Production FE | Add `http://localhost:3000` during local development |
| `TOKEN_TTL` | 900 | Token lifetime (seconds), player auto-renews |
| `NATIVE_TOKEN_TTL` | 14400 | Token lifetime for older Safari/iOS native HLS |
| `IP_PREFIX_V4` / `IP_PREFIX_V6` | 24 / 64 | Subnet mask for IP binding; `0` = disabled |

Recommended: Configure a **Rate limiting rule** (Security → WAF) for host `ffmpeg-cdn…`, path matching `/token`, e.g. 30 requests / 10 seconds / IP, to mitigate automated scraping.

## Testing
```bash
npm test     # requires php in PATH: tests real grant signing with StreamToken.php
```
