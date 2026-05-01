# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

(no changes yet)

## [0.2.2] - 2026-05-01

End-to-end testing of 0.2.1 against a real `bsky.social`-hosted account
(PDS: `rhizopogon.us-west.host.bsky.network`) surfaced four bugs that
prevented uploads from completing. All four are fixed; the full pipeline
(CREATED → ENCODING → SCANNED → COMPLETED → blob → post) now works.

### Fixed

- **Service-auth `aud` claim must be the user's PDS DID, not the video service DID.** 0.2.1 minted tokens with `aud=did:web:video.bsky.app` (auto-derived from `videoServiceUrl`); the video service rejected with HTTP 401 — *"invalid token audience: should be the user's PDS DID `did:web:<pdsHost>`"*. `VideoService::mintServiceToken()` now derives `aud` from `$session->pdsUrl` on every call. The `$serviceDid` field, `deriveServiceDid()` helper, and constructor URL validation are removed; the docs that promised `did:web:<host of videoServiceUrl>` semantics are corrected.
- **`lxm` for `uploadVideo` must be `com.atproto.repo.uploadBlob`, not `app.bsky.video.uploadVideo`.** The video service authorizes uploads as generic blob uploads — using the lexicon's own method name returned HTTP 401 *"invalid token lexicon method"*. Only the upload path is affected; `getJobStatus` and `getUploadLimits` keep their lexicon-defined `lxm` values.
- **Upload response is flat (`{did, jobId, state}`), not wrapped under `jobStatus`.** The lexicon-generated `UploadVideoOutput::fromArray()` expected `{"jobStatus": {...}}` and crashed with *"Field "jobStatus": expected array, got null"*. `VideoService::uploadVideo()` now normalizes the flat shape into the wrapped envelope before parsing. (`getJobStatus` correctly returns the wrapped form — that path is unchanged.)
- **HTTP 409 `already_exists` on upload is now treated as success, not an exception.** The Bluesky video service deduplicates uploads by content hash; re-uploading bytes Bluesky has already processed (e.g. from a previous attempt that failed after upload but before post creation) returns 409 with a perfectly valid `JOB_STATE_COMPLETED` body and a usable `jobId`. `VideoService::uploadVideo()` now intercepts this specific case and converts it to a successful `UploadVideoOutput`, so callers can pass the `jobId` straight to `awaitVideo()` / `getJobStatus()` to recover the existing blob. Other 409s (no `already_exists` error, no `jobId`) still surface as `ApiException`.

### Changed

- `ApiException` now exposes the raw decoded response body via `public readonly array $body`. Used by `VideoService::uploadVideo()` to recover the dedupe `jobId` from a 409, and useful generally for endpoints whose error responses carry application-level signals. The constructor signature gained an optional `array $body = []` parameter before `$previous`; subclasses (`NotFoundException`, `AuthException`, `ValidationException`, `ServerException`, `RateLimitException`) inherit the new shape and `RateLimitException::__construct` forwards `$body` through.
- `ApiException::fromResponse()` fallback message changed from `"Unknown error"` to `"<error> (HTTP <status>)"` when the response body has no `message` field — the video service often returns `error`+`jobId` without one. `$e->getMessage()` is now informative on its own, no need to also inspect `$e->status` / `$e->error`.
- `VideoService::__construct()` no longer validates `$videoServiceUrl` at construction time (validation was tied to the now-removed DID derivation). A malformed URL surfaces at first call as a curl error.
- Job-state constants `JOB_STATE_COMPLETED` and `JOB_STATE_FAILED` promoted from `private const` on `Client` to `public const` on `VideoService` — single source of truth for the lexicon-defined values, accessible to callers who want to compare `$jobStatus->state` themselves. Open-set string typing is preserved (the lexicon explicitly leaves room for new states).

### Removed

- `curl_close($ch)` call in the curl transport. Deprecated in PHP 8.5 and a no-op since 8.0 — `CurlHandle` is auto-released when the variable goes out of scope.

## [0.2.1] - 2026-05-01

### Fixed

- **Video upload now actually works.** Previously, `$client->video->uploadVideo()`, `getJobStatus()`, and `getUploadLimits()` posted to `<pdsUrl>/xrpc/app.bsky.video.*` — Bluesky does not implement video on the PDS, so calls against `bsky.social`-hosted accounts (the vast majority) returned **HTTP 501 "Method Not Implemented"**. Calls now route to the Bluesky video processing service at `https://video.bsky.app`, with a per-call service-auth JWT minted via `com.atproto.server.getServiceAuth` (audience auto-derived as `did:web:<host>` from the configured URL, `lxm=app.bsky.video.<method>`, 30s expiry). Matches the routing behavior of the official `@atproto/api` TypeScript SDK.
- `Client::awaitVideo($jobId)` now resolves to a real `BlobRef` end-to-end (the polling loop was correct but unreachable while `getJobStatus` was failing on the PDS).

### Added

- **`Client::postVideo(string $text, string $bytes, string $alt = '', ...): PostRef`** — one-shot helper that uploads, awaits processing, and posts in a single call. Accepts the same `tags` / `langs` / `createdAt` options as `post()`. Was on the v0.2 roadmap.
- **`Client::uploadVideo(string $bytes, ?string $mimeType = null, int $timeoutSeconds = 120): BlobRef`** — mirrors `uploadImage()` for the video case. Auto-detects MIME via fileinfo if omitted (falls back to `video/mp4`). Combines `video->uploadVideo()` + `awaitVideo()` into one call returning a ready-to-embed `BlobRef`. Use this when you want to reuse the blob (post + reply, retries, `recordWithMedia` quotes); use `postVideo()` for the simple one-shot.
- `Gimucco\Bluesky\VideoService` — hand-written wrapper for the three video methods, plumbed into `Client::$video`. Lives outside `src/Generated/`, so a future lexicon regen will not overwrite the fix.
- Optional `Client` constructor parameters: `string $videoServiceUrl = 'https://video.bsky.app'` (override for hypothetical third-party video services — service DID is auto-derived as `did:web:<host>` so service-auth tokens are minted with the correct audience) and `?Closure $videoHttpTransport = null` (testing seam — the curl transport is the default).
- Curl transport now sets explicit timeouts: **10s connect**, **120s total** for `uploadVideo`, **30s total** for `getJobStatus` / `getUploadLimits`. Explicit `CURLOPT_SSL_VERIFY{PEER,HOST}` and `CURLOPT_FOLLOWLOCATION = false` for defense-in-depth against weird local php.ini overrides. No more risk of a hung connection blocking a worker forever.
- Curl transport now handles non-JSON error responses gracefully — a 502 from a CDN upstream returning HTML used to crash with `JsonException`; it now raises the appropriate `ApiException` subclass (`ServerException` for 5xx, etc.) with whatever status was received.
- `?name=` query param on `uploadVideo` now reflects the MIME type (`video.mp4` / `video.webm` / `video.mov` / `video.mpeg`) for readable server-side logs.
- 9 new tests covering service-auth minting, per-method `lxm` binding, fresh-token-per-call, custom `videoServiceUrl` with derived service DID, MIME-aware filename, distinct upload/status timeouts, URL validation, and the new `Client::uploadVideo` / `Client::postVideo` happy paths + input validation. Existing video tests updated for the new routing. **141 tests / 433 assertions** total (was 122 / 363).
- `tests/manual/video-upload.php` — real-account smoke test for the end-to-end flow (upload → post → cleanup). Listed in `tests/manual/README.md`.

### Changed

- `Client::$video` is now typed as `Gimucco\Bluesky\VideoService` (was `Gimucco\Bluesky\Generated\Methods\App\Bsky\Video`). The three public methods (`uploadVideo`, `getJobStatus`, `getUploadLimits`) keep identical signatures and return types, so callers using `$client->video->...` are unaffected. Code that explicitly type-hints against the generated `Video` class (rare — it is an internal of the lexicon-generated layer) needs to switch to `VideoService`.
- `examples/video-upload.php` simplified to use `$client->postVideo(...)` directly.
- `.php-cs-fixer.dist.php` aligned with `gimucco/atproto-php`'s config: adds `@PER-CS2.0:risky`, `strict_param`, and `native_function_invocation` (with `@compiler_optimized` scope, `strict: true`); drops `global_namespace_import` (atproto-php does not use it). Mechanical `\` prefixes added to compiler-optimized native calls (`\count`, `\strlen`, `\sprintf`, `\is_*`) across 14 existing files — runtime behavior unchanged.

## [0.2.0] - 2026-05-01

### Changed

- **Minimum PHP raised from 8.1 to 8.2.** PHP 8.1 reached end-of-life on 2025-12-31, so the floor moves to the lowest still-supported version. CI matrix updated accordingly (8.2 / 8.3 / 8.4).
- **Bumped `gimucco/atproto-php` from `^0.1.2` to `^0.2`.** The new release upgrades `web-token/jwt-library` from `^3.3` to `^4`, which resolves install-time conflicts with modern transitive deps (`brick/math` ≥ 0.13, `paragonie/constant_time_encoding` ≥ 3, `symfony/console` ≥ 8). Consumers stuck on `^0.1.2` of atproto-php would fail to install alongside any of those.
- Dev requirement `phpunit/phpunit` bumped from `^10.5` to `^11.0` (the latest line compatible with PHP 8.2). No production code changes — tests use no APIs that changed between PHPUnit 10 and 11.

## [0.1.0] - 2026-05-01

Initial release of `gimucco/bluesky-php` — a typed PHP client for the Bluesky / AT Protocol API, built on `gimucco/atproto-php` for OAuth 2.1 / DPoP authentication.

### Posting

- `Client::post()` — text post with up to 5 embed types (mutually exclusive): `images`, `video`, `external`, `quoting` (record or recordWithMedia)
- `Client::reply()` — reply to a post, with the same embed support
- `Client::thread(string $first, string ...$rest)` — chain N posts as linked replies; useful for content over Bluesky's 300-grapheme limit
- `Client::setDefaultThreadDelay(int $seconds)` — sets a **persistent** inter-post delay used by all subsequent thread() calls on this Client (returns `$this` for fluent setup but mutates state — reset to 0 to disable)
- `Client::deletePost()`

### Embeds (all 5 lexicon embed types covered)

- `EmbeddedImage(BlobRef $blob, string $alt = '')` — `app.bsky.embed.images`, max 4 per post
- `EmbeddedVideo(BlobRef $blob, string $alt = '')` — `app.bsky.embed.video`
- `EmbeddedExternal(string $uri, string $title, string $description, ?BlobRef $thumb = null)` — `app.bsky.embed.external`. Rejects non-`http(s)` URIs at construction.
- `EmbeddedRecord(string $uri, string $cid)` — `app.bsky.embed.record` (quote post)
- `EmbeddedRecordWithMedia(EmbeddedRecord, ?array $images = null, ?EmbeddedVideo $video = null)` — `app.bsky.embed.recordWithMedia` (quote + media)

Lexicon constraints enforced client-side: max 4 images, single embed type per post.

### Engagement & social graph

- `Client::like()` / `Client::unlike()` — `like()` accepts `(string|AtUri, string)`, a `PostRef` from a prior post(), or a `PostView` from a feed read; returns `LikeRef`
- `Client::repost()` / `Client::unrepost()` — same single-arg overload as `like()`; returns `RepostRef`
- `Client::follow()` / `Client::unfollow()` — returns/accepts `FollowRef`. Auto-resolves handles to DIDs.
- `Client::block()` / `Client::unblock()` — returns/accepts `BlockRef`. Auto-resolves handles to DIDs.
- `Client::mute()` / `Client::unmute()` — local mutes via `app.bsky.graph.muteActor`/`unmuteActor`

All "un-X" methods accept `string|AtUri|RecordRef`. The new `RecordRef` interface is implemented by the 5 Ref classes (PostRef, FollowRef, LikeRef, RepostRef, BlockRef) so `$client->unlike($likeRef)` type-checks. The interface deliberately excludes other Stringable types (Did, Handle) that would otherwise compile but produce confusing server errors.

### Reading

- `Client::myProfile()` — own profile (no DID lookup needed)
- `Client::getPost($uri)` — single post by AT-URI; throws `NotFoundException` if missing
- All 90+ generated read methods on `$client->actor`, `$client->feed`, `$client->graph`, etc.

### Media uploads

- `Client::uploadImage(string $bytes, ?string $mimeType = null): BlobRef` — auto-detects MIME via fileinfo if not provided
- `Video::uploadVideo(string $bytes, string $mimeType = 'video/mp4')` — generated from lexicon, async
- `Client::awaitVideo(string $jobId, int $timeoutSeconds = 120, int $initialPollSeconds = 1): BlobRef` — polls `Video::getJobStatus` with exponential backoff (cap 10s/poll), returns the BlobRef on completion. Throws on `JOB_STATE_FAILED`, timeout, or missing-blob completion. **Blocks the calling thread** — CLI / cron / queue use only.

Both raw-body upload endpoints (`com.atproto.repo.uploadBlob` and `app.bsky.video.uploadVideo`) route through `Session::authenticatedRawRequest()` (added in `gimucco/atproto-php ^0.1.2`).

### Pagination

- **28 typed `paginate*` methods** auto-generated alongside cursor-based endpoints (e.g. `feed->paginateTimeline()`, `graph->paginateFollowers()`), each returning a `Generator` and walking pages automatically with `?int $maxItems`.
- `Pager::iterate(callable, ?int $maxItems)` — closure-based helper for endpoints with custom shapes.

### Typed identifiers

- `Did`, `Handle`, `AtUri` — readonly, `Stringable`, validated on construction (throws `InvalidArgumentException` on bad input)
- 5 Ref classes (`PostRef`, `FollowRef`, `LikeRef`, `RepostRef`, `BlockRef`) share `RefTrait`, all `Stringable` to their `uri`

### Rich text

- `RichText` parses post text for facets (links, mentions, hashtags) with byte-offset spans
- Mention resolution capped at `RichText::MAX_MENTIONS = 25` to prevent unbounded API calls
- Only `NotFoundException` is silently skipped during handle resolution; other failures bubble up

### Code generation

- **93 typed API methods** across 14 method classes, generated from official Bluesky lexicon JSONs
- **250 generated value-object types** with `fromArray()` / `toArray()` and precise `array{}` shape PHPDoc
- Generated `@throws \Gimucco\Bluesky\Exception\ApiException` on every method
- `Internal\Cast` helpers narrow `mixed` JSON values, including a field-name hint in error messages (`Field "labels": expected list, got string`)
- `bin/generate-lexicons` produces PHPStan level 10-clean output
- `bin/sync-lexicons` downloads from upstream — pinned to the resolved commit SHA, with ZIP-slip protection
- `lexicons/` JSON files are **not committed** (fetched on demand); only `lexicons/SOURCE.txt` and `lexicons/MANIFEST.txt` are tracked

### Skipped namespaces

`com.atproto.sync.*`, `chat.bsky.*`, `tools.ozone.*`, `com.atproto.admin.*`, `com.atproto.temp.*`, `com.atproto.moderation.*`, `app.bsky.unspecced.*` — moderation, sync, and unstable surfaces are out of scope for this library.

### Exception hierarchy

```
BlueskyException                          # base
├── ApiException                          # any HTTP error
│   ├── NotFoundException                 # 404
│   ├── RateLimitException                # 429 (exposes ->retryAfter)
│   ├── ValidationException               # 400 from API
│   ├── AuthException                     # 401, 403
│   └── ServerException                   # 5xx
├── LexiconException                      # malformed server response
└── InvalidArgumentException              # bad input to this library
```

### Tooling & quality

- PHPStan **2.x level 10** with strict-rules — clean
- PER-CS 2.0 via `php-cs-fixer`
- PHPUnit 10 with `#[Test]` attributes
- 122 tests / 363 assertions
- GitHub Actions: tests, static analysis, lexicon freshness check
- `composer audit` — no vulnerable dependencies

### Logging

- Optional PSR-3 `LoggerInterface` injection on `Client`
- `debug`-level events on every convenience method
- **Privacy note**: log context includes subject DIDs and post URIs — a shared log file becomes a who-blocks-whom audit trail. Configure your logger accordingly.

### Notes on what's not included

- **Native enums**: Bluesky's lexicons contain 6 strict `enum` constraints, but all are in skipped `tools/ozone/*` namespaces. The 114 `knownValues` lists are explicitly forward-compatible "open sets" where introducing a closed PHP enum would break compatibility, so they remain typed as `string`.
- **App passwords**: deprecated by Bluesky; this library is OAuth-only via `gimucco/atproto-php`.
- **Retry middleware**: `RateLimitException::$retryAfter` is exposed; automatic retry/backoff is on the v0.2 roadmap.
