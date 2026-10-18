# FFmpeg Stream — API

Laravel + GraphQL (Lighthouse) API for uploading video, encoding AES-128 encrypted HLS with FFmpeg, and streaming via Cloudflare R2 + Worker. The Nuxt frontend is located in the `FE-FFMPEG` repository.

```
Upload ─▶ Laravel (GraphQL) ─▶ Queue (sequential, one job per step):
            SegmentVideoJob      select renditions, create per-rendition AES key, capture & encrypt poster
            EncodeRenditionJob   480p ─▶ upload    (one job per rendition)
            EncodeRenditionJob   720p ─▶ upload
            EncodeRenditionJob  1080p ─▶ upload
            FinalizeVideoJob     verify target files ─▶ master playlist ─▶ READY, delete original video
Watch  ─▶ Worker cloudflare/video-cdn (IP subnet-bound token, edge cache) ─▶ R2
            AES key: Worker validates token, then retrieves from Laravel /videos/keys/{keyId}
```

### Privacy & Security
- **Encrypted HLS Storage**:
  - Segments are encrypted with AES-128, **one unique key per quality tier**; keys are stored exclusively in the database (`video_renditions`, encrypted using `APP_KEY`).
  - Posters are encrypted with AES-256-GCM (key derived from `VIDEO_STREAM_SECRET`, not stored on disk).
  - Directory paths and all filenames are randomized; playlists contain no video IDs, resolution labels, or API URLs.
- **FFmpeg & Upload Hardening**:
  - Uploaded videos have extensions determined from content MIME type (`$file->extension()`) validated against an allowed video extension whitelist, falling back to `mp4` to prevent client extension spoofing.
  - User-uploaded files opened by FFmpeg and FFProbe enforce `-protocol_whitelist file` in `PosterGenerator`, `HlsEncoder`, and `SegmentVideoJob` to prevent protocol injection attacks (e.g. crafted m3u8/concat scripts reading local files or calling external networks).
- **Encoding & Lifecycle Safety**:
  - Re-segmenting (`segmentVideo`) rejects requests if the video is currently `pending` or `processing` to avoid concurrent runs.
  - Validates that the original video file exists on local disk before changing status or dispatching.
  - If a re-encode fails (`abandon()` or `failed()`), `Video::hasValidHls()` verifies whether the previous HLS files remain intact; if so, it preserves `ready` status rather than breaking playback by marking `failed`.
  - `FinalizeVideoJob` verifies that the master playlist, rendition playlists, and `.ts` segments are present on target storage before marking `ready`, cleaning old renditions, and deleting the original file.
- **GraphQL & Session Security**:
  - `user(id: ID! @eq)` only supports lookup by user ID; the `email` argument is disabled to prevent user enumeration / account discovery.
  - `logout` explicitly revokes both the access token and its corresponding `oauth_refresh_tokens` record.
  - `resetPassword` invalidates all active user sessions (access tokens and refresh tokens).
  - `updateProfile` with password change invalidates all other active sessions while preserving the current session.

## Requirements
- PHP 8.4+ (extensions: pdo_mysql, mbstring, openssl, curl, xml, zip), Composer
- MySQL 8
- FFmpeg + ffprobe: `sudo apt-get install ffmpeg` (verify with: `ffmpeg -version`)
- nginx + php-fpm, supervisor (production)
- Node 20+ (required only for deploying the Cloudflare Worker)

## Installation

```shell
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan passport:keys
php artisan passport:client --password --public   # record client ID in PASSPORT_PASSWORD_CLIENT_ID
php artisan storage:link
```

Set appropriate directory permissions for the webserver / queue worker user:
```shell
chown -R [webserver_user]:[webserver_group] .
```

### Key Environment Variables (`.env`)
| Variable | Description |
|---|---|
| `APP_URL` | API domain, e.g. `https://api.example.com` |
| `FRONTEND_URL` | Frontend domain, used for links in emails |
| `QUEUE_CONNECTION` | `database` |
| `FFMPEG_BIN` | Path to ffmpeg binary, default `/usr/bin/ffmpeg` |
| `PASSPORT_PASSWORD_CLIENT_ID` | Client ID generated during installation |
| `VIDEO_HLS_DISK` | `local` (HLS stored in `storage/app`, served by PHP) or `r2` (Cloudflare R2 + Worker) |
| `VIDEO_CDN_URL`, `VIDEO_STREAM_SECRET`, `VIDEO_ALLOWED_ORIGINS`, `R2_*` | Required only when `VIDEO_HLS_DISK=r2`, see below |

Encoding parameters (rendition tiers, CRF, x264 preset, `-tune animation`, segment length) are defined in `config/video.php`, customizable via `VIDEO_CRF`, `VIDEO_X264_PRESET`, `VIDEO_X264_TUNE`, etc.

## Storage & Streaming on Cloudflare R2

When `VIDEO_HLS_DISK=r2`, encoded videos are uploaded to R2 and viewers download segments directly via the `cloudflare/video-cdn` Worker (saving VPS bandwidth). Bucket setup, API credentials, and Worker deployment guide: **[cloudflare/video-cdn/README.md](cloudflare/video-cdn/README.md)**.

- Each video records its storage location (`videos.hls_disk`): changing `VIDEO_HLS_DISK` only affects subsequent encodes.
- `VIDEO_STREAM_SECRET` must match the Worker secret `STREAM_SECRET`.

## Queue Worker

Video encodes run in the background queue, one video at a time (preventing out-of-memory errors on small VPS instances).

Development:
```shell
php artisan queue:listen --timeout=10800
```

Production (supervisor): a configuration template is provided at `supervisor/laravel-worker.conf.example`. Adjust paths and user for your system, then place it at `/etc/supervisor/conf.d/laravel-worker.conf`.

After deploying new code or updating `.env`:
```shell
php artisan config:clear
php artisan lighthouse:cache        # if graphql/*.graphql files changed
php artisan queue:restart           # reload worker with updated code and configuration
```

## Useful Commands

```shell
# Generate missing posters for processed videos (extracted from HLS, original video not required)
php artisan videos:posters             # all missing videos
php artisan videos:posters 11 15       # specific videos
php artisan videos:posters --force     # regenerate all

# Upgrade videos encoded in legacy format (single shared key, exposed filenames,
# unencrypted posters) to the current private format. Original video not required.
php artisan videos:upgrade-storage
```

## Testing

```shell
# Run full PHP test suite (Feature & Unit tests via SQLite in-memory)
php artisan test

# Run focused test suites
php artisan test --filter=AuthenticationTest  # auth, token revocation & session invalidation
php artisan test --filter=SegmentVideoTest    # video resegmenting, status checks, ready preservation
php artisan test --filter=UploadVideoTest     # upload MIME/extension hardening
php artisan test --filter=CommentTest         # comments, threading, and mentions
php artisan test --filter=ChannelTest         # channel privacy, public videos, and username query

# Cloudflare Worker tests
cd cloudflare/video-cdn && npm test   # test Worker + cross-verify token compatibility with PHP
```
