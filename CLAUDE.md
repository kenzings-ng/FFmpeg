# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

API Laravel 12 + Lighthouse (GraphQL) cho app upload / encode / phát video HLS mã hóa. Frontend Nuxt nằm ở repo riêng `FE-FFMPEG`. Comment, message lỗi và commit đều viết bằng **tiếng Việt**: giữ nguyên quy ước này.

## Lệnh thường dùng

```shell
composer install
php artisan migrate
php artisan queue:listen --timeout=10800      # dev: chạy job encode (QUEUE_CONNECTION=database)

# Test: phpunit.xml ép SQLite in-memory (+ tắt cache schema Lighthouse), không bao giờ chạm DB trong .env.
# Cần extension pdo_sqlite; máy không có thì test báo "could not find driver" (an toàn, không rơi về MySQL).
php artisan test
php artisan test --filter=CommentTest
php artisan lighthouse:validate-schema

# Cloudflare Worker (cloudflare/video-cdn)
cd cloudflare/video-cdn && npm test          # node --test; gọi `php` + vendor/ của repo này để kiểm tra chéo định dạng
npx wrangler deploy                          # cần wrangler.toml (gitignored; copy từ wrangler.toml.example)

./vendor/bin/pint                            # format PHP (Laravel Pint)
```

CI (`.github/workflows`): PHP 8.5, `composer validate` (không `--strict`, vì `arcanedev/*` chỉ có `12.x-dev`), migrate + `php artisan test` trên SQLite, cần `php artisan passport:keys` chạy **sau** bước `chmod 777 storage` (Passport từ chối key có quyền 777).

Sau khi đổi code/config trên môi trường đang chạy:
- Sửa `graphql/**/*.graphql`: chạy `php artisan lighthouse:cache`. Schema được cache khi `APP_ENV != local`.
- Sửa job hoặc config: chạy `php artisan queue:restart`, vì worker giữ code cũ trong bộ nhớ.

## Cấu trúc source

Luồng chính: **GraphQL schema** (`graphql/`) → **resolver** (`app/GraphQL/Mutations`) → **job** (`app/Jobs`) → **lõi xử lý video** (`app/Video`) → **storage** (local hoặc R2) → **Worker** (`cloudflare/video-cdn`) phát cho người xem.

### API GraphQL
```
graphql/
├── schema.graphql            # scalar DateTime / Upload + #import các domain bên dưới
├── comment/comment.graphql   # query comment(id) (để tải replies), mentionSuggestions (gợi ý "@");
│                             #   mutation createComment, updateComment, deleteComment;
│                             #   type Comment (mentions); extend Video { comments_count, comments }
├── auth/auth.graphql         # type Mutation gốc: register (username tùy chọn), login, refreshToken, logout,
│                             #   resendVerificationEmail, forgotPassword, resetPassword; query usernameAvailable
├── user/user.graphql         # type Query gốc: me, user, users, userByUsername (trang kênh);
│                             #   mutation updateProfile (tên, handle, bio, mật khẩu);
│                             #   type User (username = handle, bio, videos_count, videos = video công khai)
└── video/video.graphql       # query videos (lọc mine), video; mutation uploadVideo, updateVideo,
                              #   setVideoVisibility, segmentVideo, deleteVideo; type Video, VideoStream
app/GraphQL/Mutations/        # mỗi mutation cần logic riêng = 1 class __invoke (query đơn giản dùng directive)
├── Register / Login / RefreshToken / Logout     # cấp, thu hồi token qua PassportTokenIssuer
├── ForgotPassword / ResetPassword / ResendVerificationEmail / UpdateProfile
├── UploadVideo               # tạo Video (private, pending), lưu file gốc videos/{id}/original.*, dispatch SegmentVideoJob
├── SegmentVideo              # xử lý lại: đặt pending, dispatch SegmentVideoJob (cần video gốc còn trên disk)
├── UpdateVideo / SetVideoVisibility             # đổi tiêu đề, đổi public/private
├── DeleteVideo               # xóa file gốc + mọi thư mục HLS (đang phát, đang encode dở) rồi xóa record
└── CreateComment / UpdateComment / DeleteComment   # bình luận: gắn trả lời vào gốc, đánh dấu đã sửa, sync @mentions
app/GraphQL/Queries/          # UsernameAvailable (kiểm tra handle khi gõ), MentionSuggestions (gợi ý "@"),
                              #   UserByUsername (trang kênh, nhận cả handle cũ còn giữ chỗ)
app/Support/Username.php      # quy tắc handle: định dạng, từ dành riêng, sinh từ tên, tìm @handle trong text,
                              #   searchable() bỏ dấu. Khớp FE utils/username.ts
app/Rules/Username.php        # rule validate argument username (dùng Support\Username::problem)
app/Services/PassportTokenIssuer.php  # gọi AccessTokenController::issueToken() trực tiếp (không có route /oauth)
app/Policies/VideoPolicy.php          # view = public hoặc là chủ; update / delete = chủ
app/Policies/CommentPolicy.php        # view = xem được video; update = người viết; delete = người viết hoặc chủ video
```

### Xử lý video
```
app/Jobs/
├── SegmentVideoJob.php       # bước 1: chọn mức, tạo VideoRendition + khóa, chụp & mã hóa ảnh bìa, xếp chuỗi job;
│                             #   abandon() = dọn dẹp khi chuỗi lỗi hẳn
├── EncodeRenditionJob.php    # bước 2 (× số mức): encode 1 mức vào tmp/encode/… rồi publish lên disk lưu trữ
└── FinalizeVideoJob.php      # bước 3: ghi master playlist, READY, dọn bản HLS cũ, xóa video gốc
app/Video/
├── HlsEncoder.php            # gọi ffmpeg cho 1 mức (CRF + maxrate, không upscale), đo BANDWIDTH,
│                             #   đổi tên segment ngẫu nhiên, trỏ khóa về {key_id}.key; targetHeights(), randomName()
├── HlsStorage.php            # chép thư mục làm việc (disk local) sang disk lưu trữ (local / r2); disk() đọc config
├── HlsPlaylist.php           # đọc master / variant playlist (variant, segment, IV) cho các lệnh artisan
├── PosterGenerator.php       # ffmpeg chụp ảnh bìa JPEG (bộ lọc thumbnail, mốc 10% thời lượng)
├── PosterVault.php           # mã hóa / giải mã ảnh bìa AES-256-GCM            ⇄ cloudflare/video-cdn/src/poster.js
└── StreamToken.php           # ký grant, chữ ký URL ảnh bìa; kiểm tra stream token ⇄ cloudflare/video-cdn/src/token.js
app/Models/
├── Video.php                 # status, is_public, hls_disk, hls_playlist_path, poster_path;
│                             #   hlsUrl(), posterUrl() (URL ký), streamAccess() (grant), scope viewableBy
├── VideoRendition.php        # 1 mức của 1 lần encode: hls_dir, height, playlist_name, key_id, encrypted_key, stream_inf
├── Comment.php               # bình luận 2 tầng: parent_id (gốc), reply_to_user_id, edited_at; mentions, syncMentions()
├── CommentMention.php        # 1 lần nhắc tên: handle như lúc viết + user_id
├── UsernameChange.php        # lịch sử handle cũ (giới hạn số lần đổi + giữ chỗ handle cũ)
└── User.php                  # username (tự sinh khi tạo), name_search (tên bỏ dấu để tìm), bio, changeUsername(),
                              #   publicVideos (công khai + ready), findByHandle() (kể cả handle cũ)
app/Console/Commands/
├── GenerateVideoPosters.php  # videos:posters: tạo ảnh bìa từ HLS đã mã hóa
└── UpgradeVideoStorage.php   # videos:upgrade-storage: chuyển định dạng cũ → định dạng riêng tư hiện tại
config/video.php              # disk HLS, CDN URL, secret token, các mức chất lượng, CRF, preset, tune, độ dài segment
```

### HTTP ngoài GraphQL
```
routes/videos.php             # middleware group 'videos' (app/Http/Kernel.php)
├── GET /videos/{video}/hls/{file}  → VideoStreamController  # phát HLS + khóa + ảnh bìa của video lưu trên disk local
└── GET /videos/keys/{keyId}        → VideoKeyController     # khóa AES cho Worker (video trên R2), kiểm tra stream token
routes/web.php
└── GET /email/verify/{id}/{hash}   → VerifyEmailController  # bấm link xác thực email → redirect FE /email-verified
app/Providers/
├── AppServiceProvider.php    # Passport (tắt route /oauth, TTL token, scope remember), URL + nội dung email tiếng Việt
└── RouteServiceProvider.php  # các rate limiter, nạp routes/videos.php
app/Http/Controllers/VideoControler.php   # code cũ, không còn route nào dùng
```

### Hạ tầng & cấu hình khác
```
cloudflare/video-cdn/         # Cloudflare Worker trước bucket R2
├── src/index.js              # router: đổi grant → token, phát .m3u8/.ts (cache edge), proxy khóa .key, ảnh bìa .img
├── src/token.js              # HMAC grant / stream token / chữ ký ảnh bìa, tính dải IP
├── src/poster.js             # giải mã ảnh bìa AES-GCM
├── test/worker.test.js       # giả lập R2 + cache, kiểm tra chéo với PHP thật
└── wrangler.toml.example     # binding R2, biến ALLOWED_ORIGINS, API_ORIGIN, TOKEN_TTL, IP_PREFIX_*
database/migrations/          # 2026_10_*: quyền sở hữu, status, hls_disk, poster_path, bảng video_renditions
supervisor/laravel-worker.conf.example  # queue worker production (numprocs=1, timeout 10800)
tests/Unit/                   # PosterVault, StreamToken, HlsPlaylist
tests/Feature/CommentTest.php # quyền, luồng trả lời, sửa / xóa, cascade, @mentions, gợi ý (RefreshDatabase)
tests/Feature/UsernameTest.php # sinh handle từ tên, định dạng, trùng, giới hạn đổi, giữ chỗ
tests/Feature/ChannelTest.php # trang kênh: chỉ video công khai, handle cũ, bio
tests/Feature/ExampleTest.php # smoke test /graphql
```

## Kiến trúc

### Một cổng API duy nhất: `/graphql`
- Schema được chia theo domain: `graphql/{auth,user,video}/*.graphql`, gom lại bằng `#import` trong `graphql/schema.graphql`. Chỉ `user`/`auth` khai báo `type Query`/`type Mutation` gốc, các domain khác dùng `extend type`. Resolver nằm ở `app/GraphQL/Mutations`.
- Auth dùng Passport password grant **qua mutation** `login`/`register`/`refreshToken`:
  - `App\Services\PassportTokenIssuer` gọi thẳng `AccessTokenController::issueToken()` bằng request PSR-7 tự dựng.
  - Route `/oauth/*` bị tắt (`Passport::ignoreRoutes()` trong `AppServiceProvider::register`).
  - `remember_me` = scope `remember` + refresh token 30 ngày; client phải gửi lại cờ này mỗi lần refresh.
- Phân quyền: `VideoPolicy` (`view` = public hoặc là chủ; `update`/`delete` = chủ), áp qua `@canFind` trong schema. Danh sách video lọc bằng scope `Video::viewableBy`.
- Email reset password trỏ về trang FE (`FRONTEND_URL`); email xác thực dùng route `verification.verify` trong `routes/web.php`.
- Rate limiter (`RouteServiceProvider`): `graphql`, `oauth-token`, `graphql-video`, `video-stream`, `video-key`. Riêng `video-key` giới hạn theo **token**, vì request tới từ IP của Cloudflare Worker.

### Pipeline encode (chuỗi job, chạy tuần tự)
```
UploadVideo / SegmentVideo mutation
  → SegmentVideoJob      chọn các mức (không upscale), tạo VideoRendition + khóa AES riêng, chụp ảnh bìa (mã hóa)
  → EncodeRenditionJob   1 job / 1 mức: HlsEncoder → thư mục tạm → HlsStorage::publishDirectory
  → FinalizeVideoJob     ghi master playlist → status READY, dọn bản HLS cũ, xóa video gốc
  catch (job lỗi hẳn)    → SegmentVideoJob::abandon: FAILED, xóa renditions + thư mục dở, GIỮ video gốc
```
- **Mỗi lúc chỉ một ffmpeg**, supervisor `numprocs=1` (xem `supervisor/laravel-worker.conf.example`). Server 2 vCPU / 3,3 GB từng bị OOM-kill khi encode song song. pbmedia/laravel-ffmpeg gộp mọi `addFormat()` của cùng một `save()` vào một tiến trình, nên mỗi mức phải `save()` riêng.
- Tham số encode (các mức, CRF, maxrate, preset, `-tune animation`, độ dài segment) nằm ở `config/video.php`. `HlsEncoder` đo lại `BANDWIDTH` từ segment thật, vì ffmpeg không tính đúng khi dùng CRF.
- Timeout: `EncodeRenditionJob::$timeout` = 10800, `queue.php` `retry_after` = 10860. Hai giá trị phải đi cùng nhau.

### Lưu trữ riêng tư (`VIDEO_HLS_DISK` = `local` | `r2`)
Ai đọc được storage cũng không được biết file nào thuộc video nào, và không được giải mã được:
- Thư mục `hls/{random32}` và mọi tên file đều ngẫu nhiên (`HlsEncoder::randomName`). Playlist chỉ chứa tên tương đối, khóa ghi dưới dạng `{key_id}.key`. Không có video id, độ phân giải hay URL API.
- Khóa AES-128 nằm trong `video_renditions.encrypted_key`, mã hóa bằng `APP_KEY`, mỗi mức một khóa. Cột `videos.encrypted_key` là của định dạng cũ, giờ luôn `null`.
- Ảnh bìa lưu dạng `*.img`, mã hóa AES-256-GCM bằng `App\Video\PosterVault`. Khóa = HMAC(`VIDEO_STREAM_SECRET`, `"poster\n{hls_dir}"`).
- Mỗi video tự nhớ disk của nó trong `videos.hls_disk`. Video trên `local` được phát qua `VideoStreamController` (public hoặc signed URL). Video trên `r2` được phát qua Worker.
- `php artisan videos:upgrade-storage` chuyển video định dạng cũ sang định dạng mới bằng cách giải mã và mã hóa lại, không cần file gốc. `videos:posters` tạo ảnh bìa từ HLS.

### Phát video từ R2: `cloudflare/video-cdn` (Worker)
```
GraphQL Video.stream → grant (Laravel ký, ~2 phút)
GET  cdn/hls/{dir}/token?grant=…           → Worker đổi grant lấy stream token gắn dải IP (/24, /64)
GET  cdn/hls/{dir}/*.m3u8|*.ts?token=…      → kiểm tra token + IP → cache edge / R2. Playlist được gắn sẵn token vào mọi URI
GET  cdn/hls/{dir}/{keyId}.key?token=…      → Worker chuyển sang Laravel /videos/keys/{keyId} (VideoKeyController kiểm tra lại token)
GET  cdn/hls/{dir}/{name}.img?exp=&sig=     → URL ảnh bìa do Laravel ký (Video::posterUrl) → head R2 → giải mã → JPEG
```
**Định dạng phải khớp từng byte giữa PHP và JS.** Sửa một bên thì phải sửa bên kia, rồi chạy `npm test` (có test chéo gọi PHP thật):
- token, grant, chữ ký ảnh bìa: `app/Video/StreamToken.php` ⇄ `src/token.js`
- ảnh bìa mã hóa: `app/Video/PosterVault.php` ⇄ `src/poster.js`

`VIDEO_STREAM_SECRET` (Laravel) phải trùng secret `STREAM_SECRET` của Worker. `wrangler.toml` chứa domain thật nên đã gitignore; chỉ commit `wrangler.toml.example`.

### Handle người dùng (`users.username`)
- Duy nhất, chữ thường, 3–30 ký tự `a-z 0-9 . _`, bắt đầu và kết thúc bằng chữ hoặc số. Luôn chạy `Username::normalize()` (bỏ `@`, viết thường) trước khi so sánh hoặc lưu.
- Tạo user mà không có username thì `User::booted()` tự sinh từ tên ("Bảo Nguyễn" → `baonguyen`, trùng thì `baonguyen2`).
- Đổi qua `User::changeUsername()`: tối đa 2 lần / 14 ngày. Handle cũ ghi vào `username_changes` và được giữ 14 ngày, người khác không lấy được.

### Trang kênh (`/@handle` ở FE)
- `userByUsername` dùng `User::findByHandle()`: handle cũ vừa đổi (còn giữ chỗ 14 ngày) vẫn ra đúng chủ cũ, FE đổi URL sang handle mới.
- `User.videos` / `videos_count` chỉ tính video **công khai và READY**, kể cả khi chủ kênh tự xem (giống YouTube).

### Bình luận (`graphql/comment`)
- **2 tầng** kiểu YouTube: `parent_id` luôn trỏ tới bình luận **gốc**. Trả lời một trả lời vẫn vào luồng của gốc, kèm `reply_to_user_id` để FE hiện "@Tên".
- Quyền đi theo video: xem / viết = `VideoPolicy::view`. Video chuyển private thì bình luận ẩn với người khác.
- Xóa bình luận gốc thì xóa luôn các trả lời, xóa video thì xóa toàn bộ bình luận (FK cascade).
- Nội dung là text thuần, **cho phép `<` `>`**: FE không bao giờ render bằng `v-html`. Rate limit `comment` = 10/phút/user.
- **Nhắc tên:** "@handle" trong nội dung. Lúc tạo / sửa, `Comment::syncMentions()` ghi `{handle như lúc viết, user_id}` vào `comment_mentions`. Body giữ nguyên chữ gốc. FE thay "@handle cũ" bằng handle **hiện tại** của `user_id`, nên người được nhắc đổi handle (hoặc người khác lấy lại handle cũ) thì bình luận vẫn trỏ đúng người.
- **Gợi ý "@"** (`MentionSuggestions`): người đã bình luận video đó và chủ video xếp trước, rồi tới người khác khớp đầu handle hoặc đầu một từ trong tên. Tên được so khớp không dấu qua cột `users.name_search` (collation MySQL không coi "đ" là "d"). Escape `_` / `%` trong LIKE. Tối đa 8 người, không gồm chính mình.
- Thứ tự sắp xếp nằm trong quan hệ của model (`Video::topLevelComments` mới nhất trước, `Comment::replies` cũ nhất trước). Số đếm dùng `@count` để tải theo lô.

## Quy ước repo
- File mẫu (`.env.example`, `*.example`, README, test) chỉ dùng giá trị giả như `example.com`. Không đưa domain thật, account ID, user hay đường dẫn máy chủ vào file được commit.
- Mỗi phần mới của schema GraphQL: thêm thư mục + file `.graphql` riêng, rồi thêm một dòng `#import` vào `graphql/schema.graphql`.
