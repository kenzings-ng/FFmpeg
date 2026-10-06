# FFmpeg Stream — API

Laravel + GraphQL (Lighthouse) để upload video, encode HLS mã hóa AES-128 bằng FFmpeg
và phát qua Cloudflare R2 + Worker. Frontend Nuxt nằm ở repo `FE-FFMPEG`.

```
Upload ─▶ Laravel (GraphQL) ─▶ queue: SegmentVideoJob ─▶ ffmpeg (480p/720p/1080p, CRF, AES-128) + ảnh bìa
                                                      └▶ upload HLS lên R2 (hoặc giữ trên local)
Xem    ─▶ Worker cloudflare/video-cdn (token gắn dải IP, cache edge) ─▶ R2
       ─▶ Laravel /videos/{id}/key (khóa AES, cần token)
```

## Yêu cầu
- PHP 8.2+ (ext: pdo_mysql, mbstring, openssl, curl, xml, zip), Composer
- MySQL 8
- FFmpeg + ffprobe: `sudo apt-get install ffmpeg` (kiểm tra: `ffmpeg -version`)
- nginx + php-fpm, supervisor (production)
- Node 20+ (chỉ để deploy Worker)

## Cài đặt

```shell
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan passport:keys
php artisan passport:client --password --public   # ghi client ID vào PASSPORT_PASSWORD_CLIENT_ID
php artisan storage:link
```

Phân quyền thư mục cho user chạy webserver / queue worker:
```shell
chown -R [user webserver]:[group webserver] .
```

### Biến môi trường chính (`.env`)
| Biến | Ý nghĩa |
|---|---|
| `APP_URL` | Domain API, vd. `https://api.example.com` |
| `FRONTEND_URL` | Domain FE, dùng cho link trong email |
| `QUEUE_CONNECTION` | `database` |
| `FFMPEG_BIN` | Đường dẫn ffmpeg, mặc định `/usr/bin/ffmpeg` |
| `PASSPORT_PASSWORD_CLIENT_ID` | ID client tạo ở bước cài đặt |
| `VIDEO_HLS_DISK` | `local` (HLS ở `storage/app`, PHP phát) hoặc `r2` (Cloudflare R2 + Worker) |
| `VIDEO_CDN_URL`, `VIDEO_STREAM_SECRET`, `VIDEO_ALLOWED_ORIGINS`, `R2_*` | Chỉ cần khi `VIDEO_HLS_DISK=r2`, xem bên dưới |

Tham số encode (các mức chất lượng, CRF, preset x264, `-tune animation`, độ dài segment)
nằm trong `config/video.php`, chỉnh được qua `VIDEO_CRF`, `VIDEO_X264_PRESET`, `VIDEO_X264_TUNE`…

## Lưu trữ & phát video trên Cloudflare R2

Khi `VIDEO_HLS_DISK=r2`, video encode xong được upload lên R2 và người xem tải segment
qua Worker `cloudflare/video-cdn` (không tốn băng thông VPS). Hướng dẫn tạo bucket, API key,
deploy Worker: **[cloudflare/video-cdn/README.md](cloudflare/video-cdn/README.md)**.

- Mỗi video tự nhớ nơi lưu (`videos.hls_disk`): đổi `VIDEO_HLS_DISK` chỉ ảnh hưởng video encode sau đó.
- `VIDEO_STREAM_SECRET` phải giống hệt secret `STREAM_SECRET` của Worker.

## Queue worker

Encode chạy trong queue, mỗi lần một video (tránh hết RAM trên VPS nhỏ).

Dev:
```shell
php artisan queue:listen --timeout=10800
```

Production (supervisor): mẫu cấu hình ở `supervisor/laravel-worker.conf.example`, sửa đường dẫn /
user cho đúng máy rồi đặt vào `/etc/supervisor/conf.d/laravel-worker.conf`.

Sau khi deploy code hoặc đổi `.env`:
```shell
php artisan config:clear
php artisan lighthouse:cache        # nếu có sửa graphql/*.graphql
php artisan queue:restart           # worker nạp lại code / config mới
```

## Lệnh hữu ích

```shell
# Tạo ảnh bìa cho video đã xử lý mà chưa có (lấy từ HLS, không cần file gốc)
php artisan videos:posters             # mọi video còn thiếu
php artisan videos:posters 11 15       # chỉ các video này
php artisan videos:posters --force     # chụp lại tất cả
```

## Test

```shell
php artisan test
cd cloudflare/video-cdn && npm test   # test Worker + kiểm tra chéo token với PHP
```
