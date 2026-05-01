# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

(no changes yet)

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
