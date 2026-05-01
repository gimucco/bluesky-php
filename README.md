# bluesky-php

[![Latest Version](https://img.shields.io/packagist/v/gimucco/bluesky-php.svg)](https://packagist.org/packages/gimucco/bluesky-php)
[![PHP Version](https://img.shields.io/packagist/php-v/gimucco/bluesky-php.svg)](https://packagist.org/packages/gimucco/bluesky-php)
[![CI](https://github.com/gimucco/bluesky-php/actions/workflows/ci.yml/badge.svg)](https://github.com/gimucco/bluesky-php/actions/workflows/ci.yml)
[![Static Analysis](https://github.com/gimucco/bluesky-php/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/gimucco/bluesky-php/actions/workflows/static-analysis.yml)
[![License](https://img.shields.io/github/license/gimucco/bluesky-php.svg)](https://github.com/gimucco/bluesky-php/blob/main/LICENSE)

A typed PHP client for the [Bluesky](https://bsky.app) / [AT Protocol](https://atproto.com) API — built on [gimucco/atproto-php](https://github.com/gimucco/atproto-php) for OAuth 2.1 / DPoP authentication.

- **93 typed API methods** + **28 auto-paginators** generated from official Bluesky lexicons
- **250 generated value-object types** with `fromArray()` / `toArray()`
- **Convenience methods** for posts, threads, all 5 embed types, engagement, social graph, moderation
- **PHPStan level 10** with strict-rules — fully statically typed
- **OAuth 2.1 + DPoP** — no app-password flow (deprecated by Bluesky)

## At a glance

```php
use Gimucco\Bluesky\Client;
use Gimucco\Bluesky\EmbeddedImage;

$client = new Client($session);   // session restored from atproto-php OAuth flow

$client->post('Hello world');
$client->post('With image', images: [new EmbeddedImage($blob, alt: 'A sunset')]);
$client->postVideo('Watch this', $videoBytes, alt: 'A clip');   // upload + await + post
$client->thread('First', 'Second', 'Third');
$client->reply($parentUri, $parentCid, 'Great post!');
$client->like($postUri, $postCid);
$client->follow('alice.bsky.social');           // handle auto-resolved to DID
$client->block('did:plc:troll');
$me = $client->myProfile();
$post = $client->getPost($uri);
```

## Cheat sheet

| Domain | Methods |
|--------|---------|
| **Posting** | `post()`, `reply()`, `thread()`, `deletePost()` |
| **Embeds** | `EmbeddedImage`, `EmbeddedVideo`, `EmbeddedExternal`, `EmbeddedRecord`, `EmbeddedRecordWithMedia` |
| **Engagement** | `like()` / `unlike()`, `repost()` / `unrepost()` |
| **Social graph** | `follow()` / `unfollow()`, `block()` / `unblock()`, `mute()` / `unmute()` |
| **Reading** | `myProfile()`, `getPost()` |
| **Media uploads** | `uploadImage()`, `uploadVideo()`, `postVideo()`, `awaitVideo()` |
| **Pagination** | `feed->paginateTimeline()`, `graph->paginateFollowers()`, … (28 auto-generated) + `Pager::iterate()` for custom shapes |
| **Lexicon API** | `$client->actor`, `$client->feed`, `$client->graph`, `$client->notification`, `$client->repo`, `$client->identity`, `$client->server`, `$client->label`, `$client->video`, `$client->bookmark`, `$client->labeler` |
| **Identifiers** | `Did`, `Handle`, `AtUri` (validated, `Stringable`) |
| **Refs** | `PostRef`, `FollowRef`, `LikeRef`, `RepostRef`, `BlockRef` (all `Stringable` to their `uri`) |

See `examples/` for runnable code per use case.

## Installation

```bash
composer require gimucco/bluesky-php
composer require guzzlehttp/guzzle      # recommended HTTP client
```

**Requirements:** PHP 8.2+, ext-json, ext-fileinfo, ext-curl, ext-openssl, ext-sodium (curl/openssl/sodium come from `gimucco/atproto-php`).

## Authentication: OAuth only

This library performs Bluesky/AT Protocol API calls. **It does not handle authentication** — that's [`gimucco/atproto-php`](https://github.com/gimucco/atproto-php), which implements the AT Protocol's mandatory **OAuth 2.1 + DPoP + PAR** profile.

**There is no app-password / identifier+password flow.** App passwords are deprecated; OAuth 2.1 is the only path. To use this library you set up the OAuth flow once (login redirect → callback → stored session), then restore the session by DID for subsequent API calls.

For long-running automation: log in once interactively, then reuse the persisted session indefinitely (tokens auto-refresh).

## Quick start

The shortest possible example, assuming you already have a stored session:

```php
use Gimucco\Bluesky\Client;

$client = new Client($session);   // see "OAuth setup" below for how to get $session
$client->post('Hello from bluesky-php!');
```

### OAuth setup (one-time)

1. **Generate an ES256 key**
   ```bash
   openssl ecparam -genkey -name prime256v1 -noout -out private.pem
   ```
2. **Host two static JSON files** at HTTPS URLs (`client-metadata.json` and `jwks.json`). Use `bin/generate-metadata` from `vendor/gimucco/atproto-php` to produce them.
3. **Configure & build the OAuth client** — see [`examples/login.php`](examples/login.php) and [`examples/callback.php`](examples/callback.php) for the complete browser flow, and [`examples/bootstrap.php`](examples/bootstrap.php) for the runtime restore pattern.

### Restoring a session at runtime

```php
use Gimucco\Atproto\ClientConfig;
use Gimucco\Atproto\OAuthClient;
use Gimucco\Atproto\Storage\FileSessionStore;
use Gimucco\Atproto\Storage\FileStateStore;
use Gimucco\Bluesky\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

$factory = new HttpFactory();
$oauth = new OAuthClient(
    config: new ClientConfig(
        clientId: 'https://your-app.com/atproto/client-metadata.json',
        redirectUri: 'https://your-app.com/atproto/callback',
        scope: 'atproto transition:generic',
        clientName: 'My Bluesky App',
        jwksUri: 'https://your-app.com/atproto/jwks.json',
        privateKey: file_get_contents('/secure/private.pem'),
        encryptionPassphrase: getenv('ATPROTO_PASSPHRASE'),
    ),
    sessionStore: new FileSessionStore('/var/app/sessions', getenv('ATPROTO_PASSPHRASE')),
    stateStore: new FileStateStore('/var/app/states', getenv('ATPROTO_PASSPHRASE')),
    httpClient: new GuzzleClient(['timeout' => 30]),
    requestFactory: $factory,
    streamFactory: $factory,
);

$session = $oauth->restoreSession('did:plc:abc123');
if ($session === null) {
    throw new RuntimeException('No stored session — user must complete OAuth flow first');
}

$client = new Client($session);
```

## Posting

### Plain text

```php
$ref = $client->post('Hello world');
echo $ref->uri;
```

### Reply

```php
$ref = $client->reply(
    parentUri: $parentUri,
    parentCid: $parentCid,
    text: 'Great take!',
);
```

For nested replies in a thread, also pass `rootUri` and `rootCid` of the thread root.

### Thread

```php
$refs = $client->thread(
    'First post in the thread 🧵',
    'Second post (auto-replies to the first)',
    'Third post (auto-replies to the second, root remains the first)',
);
```

For long threads, throttle to avoid burst rate limits:

```php
$client->setDefaultThreadDelay(2)->thread('First', 'Second', ...);
```

**Note**: `thread()` is not transactional — if a mid-thread post fails, prior posts remain published. See the method's docblock for partial-failure semantics.

### All 5 embed types

A post can carry **at most one** embed (the `EmbeddedRecordWithMedia` type combines a quote with images/video). Mismatch throws `InvalidArgumentException`.

```php
use Gimucco\Bluesky\{
    EmbeddedImage, EmbeddedVideo, EmbeddedExternal,
    EmbeddedRecord, EmbeddedRecordWithMedia,
};

// Images (up to 4 — alt text strongly recommended for accessibility)
$client->post('caption', images: [
    new EmbeddedImage($blob, alt: 'A sunset over the ocean'),
]);

// Video (after upload + awaitVideo)
$client->post('Watch this', video: new EmbeddedVideo($videoBlob, alt: '...'));

// Link card (with optional thumbnail blob)
$client->post('Worth a read:', external: new EmbeddedExternal(
    uri: 'https://example.com/article',
    title: 'Article title',
    description: 'Card description',
    thumb: $thumbnailBlob,    // optional
));

// Quote post
$client->post('Look at this 👇', quoting: new EmbeddedRecord($postUri, $postCid));

// Quote with media (images OR video)
$client->post('My take, with proof:', quoting: new EmbeddedRecordWithMedia(
    record: new EmbeddedRecord($postUri, $postCid),
    images: [new EmbeddedImage($blob, alt: 'screenshot')],
));
```

`EmbeddedExternal` rejects non-`http(s)` URIs at construction time.

## Media

### Images

```php
$blob = $client->uploadImage(file_get_contents('photo.jpg'));   // MIME auto-detected
$blob = $client->uploadImage($bytes, 'image/webp');             // explicit MIME
```

Empty bytes or empty MIME string throws `InvalidArgumentException`.

### Video

Bluesky processes videos asynchronously, but the convenience methods hide the polling. Three levels of API, in order of decreasing convenience:

```php
// One-shot: upload + await + post.
$ref = $client->postVideo('Watch this', $bytes, alt: 'A clip');

// Returns a BlobRef — for reuse (same video on multiple posts, retries, recordWithMedia, etc.)
$blob = $client->uploadVideo($bytes);
$client->post('Watch this', video: new EmbeddedVideo($blob, alt: '...'));
$client->reply($parent, $cid, 'see this', video: new EmbeddedVideo($blob));

// Lowest level: drive the upload + poll loop yourself.
$job = $client->video->uploadVideo($bytes);
$blob = $client->awaitVideo($job->jobStatus->jobId, timeoutSeconds: 60);
```

All three **block the calling thread** — fine for CLI / cron / queue, not appropriate inside a synchronous web request. The default await timeout is 120 s (exponential backoff capped at 10 s/poll); pass a smaller `timeoutSeconds` from a request with a max execution budget.

Video calls do **not** go to the user's PDS — Bluesky operates a dedicated video processing service at `https://video.bsky.app`. The library handles routing transparently: each call mints a short-lived per-method service-auth JWT via the user's PDS (`com.atproto.server.getServiceAuth`) and sends it as a plain bearer token to the video service. The HTTP path uses curl with explicit timeouts (10 s connect, 120 s total upload, 30 s status) and SSL verification on. If you run against a third-party PDS that operates its own video service, pass `videoServiceUrl: 'https://your-video-service'` to `new Client(...)` — the service DID is auto-derived as `did:web:<host>` so service-auth tokens are minted with the correct audience.

## Engagement

```php
$client->like($postUri, $postCid);          // returns LikeRef
$client->unlike($likeRef);                  // accepts string|AtUri|LikeRef (Stringable)

$client->repost($postUri, $postCid);
$client->unrepost($repostRef);
```

## Social graph

`follow()` and `block()` accept handle, DID, `Did`, or `Handle`. Handles are auto-resolved to DIDs (one extra API call). `mute()` accepts both directly (no resolution needed).

```php
$client->follow('alice.bsky.social');       // resolves handle → DID, then follows
$client->follow('did:plc:abc');             // direct, no resolution call
$client->follow(new Did('did:plc:abc'));    // typed
$client->unfollow($followRef);

$client->block('did:plc:troll');            // returns BlockRef
$client->unblock($blockRef);

$client->mute('spammer.bsky.social');
$client->unmute('spammer.bsky.social');
```

## Reading

```php
$me = $client->myProfile();                          // ProfileViewDetailed
$them = $client->actor->getProfile('alice.bsky.social');

$post = $client->getPost('at://did:plc:.../app.bsky.feed.post/abc');  // throws NotFoundException if missing

// Iterate the timeline (auto-pages)
foreach ($client->feed->paginateTimeline(limit: 50, maxItems: 200) as $item) {
    echo $item->post->author->handle.': '.$item->post->record['text']."\n";
}
```

The full generated API surface is on `$client->actor`, `$client->feed`, `$client->graph`, etc. See [`examples/profile-and-fetch.php`](examples/profile-and-fetch.php), [`examples/feed-walker.php`](examples/feed-walker.php), [`examples/notifications.php`](examples/notifications.php).

## Pagination

34 of the 93 generated methods accept `cursor` and return `{cursor, items}`. Each gets a typed `paginate*` companion:

```php
foreach ($client->feed->paginateTimeline() as $item) { /* ... */ }
foreach ($client->graph->paginateFollowers('alice.bsky.social') as $follower) { /* ... */ }
foreach ($client->notification->paginateNotifications() as $notif) { /* ... */ }
```

For custom shapes or non-generated endpoints, use `Pager::iterate()`:

```php
use Gimucco\Bluesky\Pager;

$items = Pager::iterate(
    fetch: fn(?string $cursor) => [
        ($r = $client->feed->getTimeline(cursor: $cursor))->feed,
        $r->cursor,
    ],
    maxItems: 500,
);
```

## Typed identifiers

```php
use Gimucco\Bluesky\{Did, Handle, AtUri};

$did = new Did('did:plc:abc123');
$client->follow($did);

$uri = new AtUri('at://did:plc:alice/app.bsky.feed.post/abc');
echo $uri->authority;    // did:plc:alice
echo $uri->collection;   // app.bsky.feed.post
echo $uri->rkey;         // abc

$h = new Handle('@alice.bsky.social');   // strips leading @
echo $h->value;                           // alice.bsky.social
```

All five Ref classes (`PostRef`, `FollowRef`, `LikeRef`, `RepostRef`, `BlockRef`) are `Stringable` to their `uri` — you can pass them directly to delete methods (`unfollow($followRef)`, etc.).

## Logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('bluesky');
$logger->pushHandler(new StreamHandler('php://stderr'));

$client = new Client($session, $logger);
```

Convenience methods emit `debug`-level events on post/follow/like/etc. **Heads up**: log context includes subject DIDs and post URIs, so a shared log file becomes a who-blocks-whom audit trail. Configure your logger accordingly.

## Error handling

```php
use Gimucco\Bluesky\Exception\{
    BlueskyException,            // base class
    ApiException,                // catch-all for HTTP errors
    NotFoundException,           // 404
    RateLimitException,          // 429 — exposes ->retryAfter (DateTimeImmutable|null)
    ValidationException,         // 400 from API
    AuthException,               // 401, 403
    ServerException,             // 5xx
    LexiconException,            // malformed response from server
    InvalidArgumentException,    // bad input to this library (distinct from API 400)
};

try {
    $client->getPost($uri);
} catch (NotFoundException $e) {
    // handle "not found"
} catch (RateLimitException $e) {
    sleep((int) ($e->retryAfter?->getTimestamp() - time() ?? 60));
    // retry...
} catch (ApiException $e) {
    // any other HTTP error
    error_log("Bluesky {$e->status}: {$e->error} — {$e->getMessage()}");
}
```

## Code generation

The `src/Generated/` directory is produced from AT Protocol lexicon JSONs (which are **not committed** — fetched on demand via `composer sync-lexicons`).

```bash
composer sync-lexicons      # download latest lexicons from upstream
composer generate           # regenerate PHP from lexicons
composer generate-check     # verify committed output is current (used in CI)
```

Skipped namespaces (out of scope for this library): `com.atproto.sync.*`, `chat.bsky.*`, `tools.ozone.*`, `com.atproto.admin.*`, `com.atproto.temp.*`, `com.atproto.moderation.*`, `app.bsky.unspecced.*`.

## Development

```bash
composer install
composer test          # PHPUnit
composer phpstan       # level 10 + strict-rules
composer cs-check      # PER-CS 2.0
composer cs-fix
```

For real-API smoke tests against your test account, see [`tests/manual/`](tests/manual/).

## Project structure

```
src/
├── Client.php                 # Main facade
├── RichText.php               # Facet parser (links, mentions, hashtags)
├── Pager.php                  # Closure-based pagination helper
├── RefTrait.php               # Shared body for the 5 Ref classes
├── Did.php                    # Validated DID value object
├── Handle.php                 # Validated handle value object
├── AtUri.php                  # Parsed at-uri value object
├── BlobRef.php                # Blob reference (uploaded blob)
├── PostRef.php, FollowRef.php, LikeRef.php, RepostRef.php, BlockRef.php
├── EmbeddedImage.php, EmbeddedVideo.php, EmbeddedExternal.php
├── EmbeddedRecord.php, EmbeddedRecordWithMedia.php
├── Exception/                 # 9 typed exceptions
├── Internal/Cast.php          # JSON narrowing helpers (used by generated code)
└── Generated/                 # Auto-generated (do not edit)
    ├── Methods/               # 14 method classes (93 methods + 28 paginators)
    └── Types/                 # 250 value-object types
bin/
├── generate-lexicons          # Code generator
├── sync-lexicons              # Lexicon downloader
└── lib/filter.php             # Shared scope/skip rules
examples/                      # 13 runnable example scripts
tests/                         # PHPUnit (Unit + Integration) + manual harness
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
