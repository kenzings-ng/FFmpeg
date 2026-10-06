# video-cdn: phát HLS từ Cloudflare R2

Worker đứng trước bucket R2 (private): kiểm tra token, cache segment ở edge
(có PoP ở Hà Nội / TP.HCM) và không tốn phí băng thông.

```
Player ──① query video { stream { grant … } } ──────────────▶ Laravel (GraphQL)
       ──② GET cdn/hls/{dir}/token?grant=…  ────────────────▶ Worker: kiểm tra grant → token gắn dải IP
       ──③ GET cdn/hls/{dir}/*.m3u8|*.ts?token=… ───────────▶ Worker: kiểm tra token+IP → cache/R2
       ──④ GET api/videos/{id}/key?token=… ─────────────────▶ Laravel: kiểm tra token → khóa AES
```

Định dạng token: `src/token.js` ⇄ `app/Video/StreamToken.php` (phải khớp nhau, có test chéo).

## Cài đặt lần đầu

### 1. R2 (Cloudflare dashboard)
1. **R2 Object Storage** → bật R2 (cần thẻ Visa/Mastercard; trong 10 GB miễn phí không bị trừ tiền).
2. **Create bucket** tên `ffmpeg-videos`, Location hint: *Asia-Pacific*.
   **Không** bật Public access / `r2.dev`.
3. **Manage R2 API Tokens** → *Create API token* → quyền **Object Read & Write**, chỉ bucket `ffmpeg-videos`.
   Ghi lại *Access Key ID*, *Secret Access Key* và endpoint `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`.

### 2. Laravel (`.env` ở thư mục gốc repo)
```dotenv
VIDEO_HLS_DISK=local            # giữ local cho tới bước 4
VIDEO_CDN_URL=https://cdn.example.com
VIDEO_STREAM_SECRET=            # openssl rand -base64 48
VIDEO_ALLOWED_ORIGINS=https://app.example.com
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=ffmpeg-videos
R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
```
Kiểm tra kết nối:
```bash
php artisan tinker --execute 'Storage::disk("r2")->put("ping.txt","ok"); echo Storage::disk("r2")->get("ping.txt"); Storage::disk("r2")->delete("ping.txt");'
```

### 3. Deploy Worker
```bash
cd cloudflare/video-cdn
cp wrangler.toml.example wrangler.toml   # sửa domain, bucket, ALLOWED_ORIGINS
npm install
npx wrangler login                 # hoặc export CLOUDFLARE_API_TOKEN=… (quyền Workers Scripts:Edit, R2:Edit, DNS)
npx wrangler secret put STREAM_SECRET   # dán ĐÚNG giá trị VIDEO_STREAM_SECRET
npx wrangler deploy
```
`wrangler deploy` tự tạo DNS + chứng chỉ cho domain khai báo trong `routes` của `wrangler.toml`
(`wrangler.toml` chứa domain thật nên đã nằm trong `.gitignore`, chỉ commit `wrangler.toml.example`).

### 4. Bật
```bash
# .env: VIDEO_HLS_DISK=r2
php artisan queue:restart          # worker nạp lại config
# rồi build + deploy lại frontend
```
Video encode **sau** khi bật mới lên R2; video cũ vẫn phát từ local.

### 5. Supervisor (cần sudo, một lần)
Timeout của worker đã tăng lên 3 giờ cho video dài:
```bash
sudo cp supervisor/laravel-worker.conf.example /etc/supervisor/conf.d/laravel-worker.conf   # sửa đường dẫn / user trước
sudo supervisorctl reread && sudo supervisorctl update
```

## Kiểm tra sau khi bật
- Upload video → file xuất hiện trong bucket dưới `hls/{id}-…/`.
- Phát được trên web; DevTools → Network: segment tải từ `ffmpeg-cdn…`, có `?token=`.
- Copy URL một segment, mở ở tab mới (không có Origin) → **403**.
- Mở bằng mạng khác (4G) với cùng token → **403**; trên web, đổi Wi-Fi ↔ 4G giữa chừng → vẫn phát tiếp.
- Xem quá 15 phút → video không dừng (player tự gia hạn token).

## Tùy chỉnh (`wrangler.toml` → `[vars]`)
| Biến | Mặc định | Ý nghĩa |
|---|---|---|
| `ALLOWED_ORIGINS` | FE production | Thêm `http://localhost:3000` khi dev |
| `TOKEN_TTL` | 900 | Hạn token (giây), player tự gia hạn |
| `NATIVE_TOKEN_TTL` | 14400 | Hạn token cho Safari/iOS cũ phát HLS native |
| `IP_PREFIX_V4` / `IP_PREFIX_V6` | 24 / 64 | Gắn token với dải IP; `0` = tắt |

Nên thêm một **Rate limiting rule** (Security → WAF) cho host `ffmpeg-cdn…`, path chứa `/token`,
ví dụ 30 request / 10 giây / IP, để chặn tool tải hàng loạt.

## Test
```bash
npm test     # cần php trong PATH: test ký grant bằng StreamToken.php thật
```
