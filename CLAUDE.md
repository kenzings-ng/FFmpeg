# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Laravel 12 + Lighthouse (GraphQL) API for uploading, encoding, and streaming encrypted HLS video. The Nuxt frontend is hosted in a separate repository (`FE-FFMPEG`). Comments, error messages, and commit messages have historically followed Vietnamese conventions.

## Common Commands

```shell
composer install
php artisan migrate
php artisan queue:listen --timeout=10800      # dev: run encode jobs (QUEUE_CONNECTION=database)

# Testing: phpunit.xml enforces SQLite in-memory (+ disables Lighthouse schema cache), never touching the DB in .env.
# Requires the pdo_sqlite extension; if missing, tests report "could not find driver" (safe, will not fall back to MySQL).
php artisan test
php artisan test --filter=CommentTest
php artisan test --filter=AuthenticationTest
php artisan test --filter=SegmentVideoTest
php artisan lighthouse:validate-schema

# Cloudflare Worker (cloudflare/video-cdn)
cd cloudflare/video-cdn && npm test          # node --test; invokes `php` + vendor/ in this repo for cross-validation
npx wrangler deploy                          # requires wrangler.toml (gitignored; copy from wrangler.toml.example)

./vendor/bin/pint                            # PHP code formatter (Laravel Pint)
```

CI (`.github/workflows`): PHP 8.5, `composer validate` (without `--strict`, since `arcanedev/*` is `12.x-dev`), migrate + `php artisan test` on SQLite. `php artisan passport:keys` must run **after** `chmod 777 storage` (Passport rejects keys with 777 permissions).

After changing code/configuration in a running environment:
- Modified `graphql/**/*.graphql`: run `php artisan lighthouse:cache`. The schema is cached when `APP_ENV != local`.
- Modified jobs or config: run `php artisan queue:restart`, as worker processes retain loaded code in memory.

## Source Structure

Main flow: **GraphQL schema** (`graphql/`) → **resolvers** (`app/GraphQL/Mutations`) → **jobs** (`app/Jobs`) → **video processing core** (`app/Video`) → **storage** (local or R2) → **Worker** (`cloudflare/video-cdn`) serving viewers.

### GraphQL API
```
graphql/
├── schema.graphql            # scalar DateTime / Upload + #import domain schemas below
├── comment/comment.graphql   # query comment(id) (to fetch replies), mentionSuggestions (autocomplete "@");
│                             #   mutation createComment, updateComment, deleteComment;
│                             #   type Comment (mentions); extend Video { comments_count, comments }
├── auth/auth.graphql         # root type Mutation: register (optional username), login, refreshToken, logout,
│                             #   resendVerificationEmail, forgotPassword, resetPassword; query usernameAvailable
├── user/user.graphql         # root type Query: me, user(id: ID! @eq), users, userByUsername (channel page);
│                             #   (user query intentionally disallows email argument to prevent account discovery)
│                             #   mutation updateProfile (name, handle, bio, password);
│                             #   type User (username = handle, bio, videos_count, videos = public videos)
└── video/video.graphql       # query videos (filtered by mine), video; mutation uploadVideo, updateVideo,
                              #   setVideoVisibility, segmentVideo, deleteVideo; type Video, VideoStream
app/GraphQL/Mutations/        # mutations with custom logic = single __invoke class (simple queries use directives)
├── Register / Login / RefreshToken / Logout     # issue and revoke tokens via PassportTokenIssuer;
│                             #   Logout explicitly revokes both the access token and its oauth_refresh_tokens record
├── ForgotPassword / ResetPassword / ResendVerificationEmail / UpdateProfile
│                             #   ResetPassword invalidates ALL active user sessions (access + refresh tokens);
│                             #   UpdateProfile revokes all OTHER sessions on password change, preserving current
├── UploadVideo               # create Video (private, pending), derives extension from content MIME ($file->extension())
│                             #   constrained to video whitelist, stores original to videos/{id}/original.*, dispatches SegmentVideoJob
├── SegmentVideo              # reprocess: checks authorization, rejects pending/processing, verifies original file on local
│                             #   disk before updating status to pending or dispatching SegmentVideoJob
├── UpdateVideo / SetVideoVisibility             # update title, toggle public/private
├── DeleteVideo               # permanently delete original file + all HLS directories (active / in-progress) and DB record
└── CreateComment / UpdateComment / DeleteComment   # comments: attach replies to root, mark edited, sync @mentions
app/GraphQL/Queries/          # UsernameAvailable (validate handle while typing), MentionSuggestions ("@" suggestions),
                              #   UserByUsername (channel page, resolves held legacy handles)
app/Support/Username.php      # handle rules: format, reserved words, derivation, extract @handle in text,
                              #   searchable() accent stripping. Matches FE utils/username.ts
app/Rules/Username.php        # validation rule for username arguments (uses Support\Username::problem)
app/Services/PassportTokenIssuer.php  # calls AccessTokenController::issueToken() directly (no public /oauth routes)
app/Policies/VideoPolicy.php          # view = public or owner; update / delete = owner
app/Policies/CommentPolicy.php        # view = can view video; update = author; delete = author or video owner
```

### Video Processing
```
app/Jobs/
├── SegmentVideoJob.php       # Step 1: select renditions, create VideoRendition + AES key, capture & encrypt poster, dispatch job chain;
│                             #   abandon() & failed() preserve READY status if old HLS is still valid (hasValidHls())
├── EncodeRenditionJob.php    # Step 2 (x rendition count): encode 1 rendition into tmp/encode/… then publish to storage disk
└── FinalizeVideoJob.php      # Step 3: write master playlist, verify all playlists and segments exist on target disk,
                              #   mark READY, clean up old HLS files, delete original file
app/Video/
├── HlsEncoder.php            # invoke ffmpeg for 1 rendition (CRF + maxrate, no upscale, -protocol_whitelist file),
│                             #   measure BANDWIDTH, randomize segment names, point key URI to {key_id}.key; targetHeights()
├── HlsStorage.php            # copy working directory (local disk) to storage disk (local / r2); disk() reads config
├── HlsPlaylist.php           # parse master / variant playlists (variants, segments, IV) for artisan commands
├── PosterGenerator.php       # ffmpeg capture JPEG poster (thumbnail filter at 10% duration mark, -protocol_whitelist file)
├── PosterVault.php           # encrypt / decrypt poster using AES-256-GCM       ⇄ cloudflare/video-cdn/src/poster.js
└── StreamToken.php           # sign grant and poster URLs; verify stream tokens ⇄ cloudflare/video-cdn/src/token.js
app/Models/
├── Video.php                 # status, is_public, hls_disk, hls_playlist_path, poster_path;
│                             #   hlsUrl(), posterUrl() (signed URLs), streamAccess() (grant), hasValidHls(), scope viewableBy
├── VideoRendition.php        # 1 rendition of an encode: hls_dir, height, playlist_name, key_id, encrypted_key, stream_inf
├── Comment.php               # 2-level comments: parent_id (root), reply_to_user_id, edited_at; mentions, syncMentions()
│                             #   supports display names, longer names, and prioritizes participants/owner
├── CommentMention.php        # mention record: handle as typed + user_id
├── UsernameChange.php        # username history (change rate limit + hold reservation for old handles)
└── User.php                  # username, name_search (unaccented for search), bio, changeUsername(),
                              #   publicVideos (public + ready), findByHandle() (including reserved old handles)
app/Console/Commands/
├── GenerateVideoPosters.php  # videos:posters: regenerate posters from encrypted HLS
└── UpgradeVideoStorage.php   # videos:upgrade-storage: migrate legacy storage format to current private format
config/video.php              # HLS disk, CDN URL, secret token, rendition tiers, CRF, preset, tune, segment length
```

### HTTP Outside GraphQL
```
routes/videos.php             # 'videos' middleware group (app/Http/Kernel.php)
├── GET /videos/{video}/hls/{file}  → VideoStreamController  # serve HLS + keys + posters for locally stored videos
└── GET /videos/keys/{keyId}        → VideoKeyController     # AES keys for Worker (R2 videos), validates stream token
routes/web.php
└── GET /email/verify/{id}/{hash}   → VerifyEmailController  # verify email link click → redirect FE /email-verified
app/Providers/
├── AppServiceProvider.php    # Passport (disable default /oauth routes, token TTL, remember scope), localized emails
└── RouteServiceProvider.php  # rate limiters, load routes/videos.php
app/Http/Controllers/VideoControler.php   # legacy code, no active routes
```

### Infrastructure & Supporting Config
```
cloudflare/video-cdn/         # Cloudflare Worker fronting R2 bucket
├── src/index.js              # router: exchange grant → token, serve .m3u8/.ts (edge cache), proxy .key, decrypt .img
├── src/token.js              # HMAC grant / stream token / poster signature, calculate IP subnet
├── src/poster.js             # AES-GCM poster decryption
├── test/worker.test.js       # mock R2 + cache, cross-verify against real PHP
└── wrangler.toml.example     # R2 bindings, ALLOWED_ORIGINS, API_ORIGIN, TOKEN_TTL, IP_PREFIX_*
database/migrations/          # ownership, status, hls_disk, poster_path, video_renditions table
supervisor/laravel-worker.conf.example  # production queue worker config (numprocs=1, timeout 10800)
tests/Unit/                   # PosterVault, StreamToken, HlsPlaylist
tests/Feature/CommentTest.php # permissions, reply threading, edit/delete, cascade, @mentions, suggestions
tests/Feature/UsernameTest.php # handle generation, formatting, collision, rate limits, reservations
tests/Feature/ChannelTest.php # channel page: public videos only, old handles, bio, user query ID lookup
tests/Feature/AuthenticationTest.php # login, logout token revocation, password reset & change session invalidation
tests/Feature/SegmentVideoTest.php   # resegmenting: status check, original file validation, ready preservation
tests/Feature/UploadVideoTest.php    # video upload MIME/extension detection & client spoofing hardening
tests/Feature/ExampleTest.php # GraphQL smoke test
```

## Architecture

### Single API Gateway: `/graphql`
- Schema is organized by domain: `graphql/{auth,user,video}/*.graphql`, aggregated via `#import` in `graphql/schema.graphql`. Only `user` and `auth` declare root `type Query` / `type Mutation`; other domains use `extend type`. Resolvers are in `app/GraphQL/Mutations`.
- Auth uses Passport password grant **via GraphQL mutations** (`login`, `register`, `refreshToken`):
  - `App\Services\PassportTokenIssuer` invokes `AccessTokenController::issueToken()` directly with a synthesized PSR-7 request.
  - Default `/oauth/*` routes are disabled (`Passport::ignoreRoutes()` in `AppServiceProvider::register`).
  - `remember_me` = `remember` scope + 30-day refresh token; client must re-supply this flag on each refresh.
  - **Session Invalidation & Revocation**:
    - `logout`: Revokes the current access token and its associated refresh token in `oauth_refresh_tokens`.
    - `resetPassword`: Revokes all active access tokens and refresh tokens belonging to the user.
    - `updateProfile` (password change): Revokes all other active sessions while preserving the current active session.
- Privacy & Lookup:
  - `user(id: ID! @eq)` only supports lookup by user ID; the `email` argument is disabled to prevent user enumeration / account discovery.
- Authorization: `VideoPolicy` (`view` = public or owner; `update`/`delete` = owner), applied via `@canFind` in schema. Video listings are filtered using `Video::viewableBy` scope.
- Password reset email links point to the frontend (`FRONTEND_URL`); email verification uses the `verification.verify` route in `routes/web.php`.
- Rate limiters (`RouteServiceProvider`): `graphql`, `oauth-token`, `graphql-video`, `video-stream`, `video-key`. `video-key` is throttled by **token** because incoming requests originate from the Cloudflare Worker IP.

### Encoding Pipeline (Sequential Job Chain)
```
UploadVideo / SegmentVideo mutation
  → SegmentVideoJob      select renditions (no upscale), create VideoRendition + AES key, capture & encrypt poster
  → EncodeRenditionJob   1 job / rendition: HlsEncoder → temp directory → HlsStorage::publishDirectory
  → FinalizeVideoJob     write master playlist, verify target files exist, mark READY, clean up old HLS, delete original
  catch (permanent error) → SegmentVideoJob::abandon: FAILED (or restore READY if valid old HLS exists), delete in-progress files, KEEP original
```
- **Only one ffmpeg process runs at a time**, supervisor `numprocs=1` (see `supervisor/laravel-worker.conf.example`). A 2 vCPU / 3.3 GB RAM server previously experienced OOM-kills during concurrent encodes. Because `pbmedia/laravel-ffmpeg` combines multiple formats in a single `save()` into one ffmpeg process, each quality tier must run its own independent job and `save()`.
- **Resegmenting Safety**:
  - `SegmentVideo` rejects requests if video is already `pending` or `processing` to prevent concurrent encoding jobs.
  - Checks `Storage::disk('local')->exists($video->original_path)` before changing state or dispatching.
  - If a re-encode fails (in `abandon()` or `failed()`), `Video::hasValidHls()` checks if the previous HLS playlist and renditions are still intact. If so, it preserves `ready` status rather than breaking playback by marking `failed`.
- **Pre-Deletion Storage Verification**:
  - `FinalizeVideoJob` verifies that the master playlist, every rendition playlist, and `.ts` segment files exist on the target storage disk before updating status to `ready`, deleting old renditions, and deleting the original file.
- **FFmpeg & Upload Security**:
  - `UploadVideo` derives extensions from the file's content MIME type (`$file->extension()`) and matches against an allowed video extension whitelist, falling back to `mp4` to prevent client extension spoofing.
  - User-uploaded files opened by FFmpeg and FFProbe use `-protocol_whitelist file` in `PosterGenerator`, `HlsEncoder`, and `SegmentVideoJob` to prevent crafted files (e.g. m3u8/concat scripts) from reading arbitrary local files or making outbound network calls.
- Encoding parameters (tiers, CRF, maxrate, preset, `-tune animation`, segment duration) live in `config/video.php`. `HlsEncoder` measures actual `BANDWIDTH` from generated segments because ffmpeg does not compute accurate bandwidth metadata with CRF.
- Timeouts: `EncodeRenditionJob::$timeout` = 10800, `queue.php` `retry_after` = 10860. These two values must remain synchronized.

### Private Storage (`VIDEO_HLS_DISK` = `local` | `r2`)
Anyone inspecting storage directly cannot identify which video a file belongs to or decrypt it:
- Directory `hls/{random32}` and all filenames are randomized (`HlsEncoder::randomName`). Playlists only reference relative paths, and key files are stored as `{key_id}.key`. No video IDs, resolutions, or API URLs are exposed.
- AES-128 keys are stored in `video_renditions.encrypted_key`, encrypted via `APP_KEY`, with an independent key per rendition tier. The legacy column `videos.encrypted_key` is now always `null`.
- Posters are stored as `*.img`, encrypted with AES-256-GCM via `App\Video\PosterVault`. Key = HMAC(`VIDEO_STREAM_SECRET`, `"poster\n{hls_dir}"`).
- Each video records its storage disk in `videos.hls_disk`. Videos on `local` are served through `VideoStreamController` (public or temporary signed URLs). Videos on `r2` are served through the Worker.
- `php artisan videos:upgrade-storage` converts legacy video storage to the current encrypted format without requiring the original file. `videos:posters` extracts posters from HLS.

### Video Playback from R2: `cloudflare/video-cdn` (Worker)
```
GraphQL Video.stream → grant (Laravel-signed HMAC, ~2 min TTL)
GET  cdn/hls/{dir}/token?grant=…           → Worker exchanges grant for IP-bound stream token (/24, /64)
GET  cdn/hls/{dir}/*.m3u8|*.ts?token=…      → Validate token + IP subnet → edge cache / R2. Playlists have token pre-appended to all URIs
GET  cdn/hls/{dir}/{keyId}.key?token=…      → Worker forwards to Laravel /videos/keys/{keyId} (VideoKeyController re-validates token)
GET  cdn/hls/{dir}/{name}.img?exp=&sig=     → Laravel-signed poster URL (Video::posterUrl) → head R2 → decrypt → JPEG
```
**Formats must match byte-for-byte between PHP and JS.** Changes on one side require matching changes on the other, followed by running `npm test` (which cross-tests using real PHP):
- Token, grant, poster signatures: `app/Video/StreamToken.php` ⇄ `src/token.js`
- Poster encryption/decryption: `app/Video/PosterVault.php` ⇄ `src/poster.js`

`VIDEO_STREAM_SECRET` (Laravel) must match the Worker secret `STREAM_SECRET`. `wrangler.toml` contains production domains and is gitignored; only `wrangler.toml.example` is committed.

### User Handles (`users.username`)
- Unique, lowercase, 3–30 characters matching `a-z 0-9 . _`, starting and ending with alphanumeric characters. Always run `Username::normalize()` (strip `@`, lowercase) before comparing or persisting.
- Automatic handle generation creates handles from user names (or randomized fallback if unspecified).
- Changed via `User::changeUsername()`: maximum 2 changes per 14-day rolling window. Old handles are recorded in `username_changes` and reserved for 14 days to prevent impersonation.

### Channel Page (`/@handle` in FE)
- `userByUsername` resolves through `User::findByHandle()`: recently changed handles still in their 14-day hold period resolve to the original owner so the frontend can redirect to the new handle.
- `User.videos` / `videos_count` only include videos that are **public and READY**, even when viewed by the channel owner (matching YouTube behavior).

### Comments (`graphql/comment`)
- **2-level hierarchy** (like YouTube): `parent_id` always points to the **root** comment. Replying to a reply remains in the root thread, with `reply_to_user_id` stored for the frontend to display "@Name".
- Permissions follow the video: viewing/writing requires `VideoPolicy::view`. If a video is set to private, its comments become hidden from other viewers.
- Deleting a root comment deletes all replies; deleting a video deletes all associated comments (foreign key cascade).
- Comment body is raw text, **allowing `<` and `>`**: frontend never renders comments via `v-html`. Rate limit: `comment` = 10 requests/minute/user.
- **Mentions:** "@handle" in comment body. On creation/update, `Comment::syncMentions()` extracts handles and persists `{handle as typed, user_id}` in `comment_mentions`. The original comment body is preserved unchanged. The frontend dynamically resolves older "@handle" mentions to the current handle of `user_id`.
- **"@" Autocomplete Suggestions** (`MentionSuggestions`): prioritized by participants on the video and the video owner first, followed by prefix matches on handles or words in display names. Names are matched accent-insensitively using `users.name_search`. Wildcards (`_`, `%`) in LIKE queries are properly escaped. Limited to 8 suggestions, excluding the author.
- Ordering is handled at the model relation level (`Video::topLevelComments` newest first, `Comment::replies` oldest first). Counts use `@count` for batch loading.

## Repository Conventions
- Sample files (`.env.example`, `*.example`, README, tests) must only contain dummy placeholder values like `example.com`. Never commit real domains, account IDs, user credentials, or internal server paths.
- Adding to GraphQL schema: create a dedicated `.graphql` file in a relevant domain directory, then add an `#import` directive to `graphql/schema.graphql`.
