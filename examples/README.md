# Examples

Working examples for `gimucco/bluesky-php`. Authentication uses **OAuth 2.1 + DPoP** via [`gimucco/atproto-php`](https://github.com/gimucco/atproto-php) — there is no app-password flow.

## Files

### Setup
| File | Purpose |
|------|---------|
| `config.example.php` | Template config — copy to `config.php` and fill in your OAuth client details |
| `bootstrap.php` | Shared helper — builds `OAuthClient` and restores a `Session` for a given DID; provides `examples_did()` and `examples_arg($pos)` |
| `login.php` | Browser-served — starts the OAuth flow (run once per user) |
| `callback.php` | Browser-served — handles the OAuth callback and persists the session |

### Posting
| File | Demonstrates |
|------|--------------|
| `post-text.php` | Plain text post |
| `reply.php` | Reply to an existing post |
| `thread.php` | Multi-post thread (linked replies, useful for content over 300 graphemes) |
| `quote-post.php` | Quote another post (`EmbeddedRecord`) — also shows quote-with-media |
| `link-card.php` | Post with link card preview (`EmbeddedExternal`, optional thumbnail) |

### Media
| File | Demonstrates |
|------|--------------|
| `post-with-image.php` | Upload an image and post it with alt text (`EmbeddedImage`) |
| `video-upload.php` | Upload a video, await async processing, post with `EmbeddedVideo` |

### Social & moderation
| File | Demonstrates |
|------|--------------|
| `follow.php` | Resolve a handle and follow |
| `moderate.php` | `block` / `unblock` / `mute` / `unmute` (CLI dispatch) |

### Reading
| File | Demonstrates |
|------|--------------|
| `profile-and-fetch.php` | `myProfile()`, `actor->getProfile()`, `getPost()` |
| `feed-walker.php` | Iterate the timeline using a generated paginator |
| `notifications.php` | Walk and mark-as-read notifications |

## One-time setup

1. **Generate an ES256 key**
   ```bash
   openssl ecparam -genkey -name prime256v1 -noout -out examples/private.pem
   ```

2. **Generate and host `client-metadata.json` and `jwks.json`**

   Use the `bin/generate-metadata` tool from `vendor/gimucco/atproto-php`:
   ```bash
   php vendor/gimucco/atproto-php/bin/generate-metadata \
       --config=examples/config.php \
       --output=/path/to/your/public/dir
   ```
   Host both files at HTTPS URLs that match `client_id` and `jwks_uri` in your config. For development, you can use [`ngrok`](https://ngrok.com/) or similar to expose `localhost`.

3. **Copy and edit the config**
   ```bash
   cp examples/config.example.php examples/config.php
   # Edit examples/config.php — set client_id, redirect_uri, jwks_uri, etc.
   ```

## Logging in (one-time per user)

Serve the `examples/` directory and visit `login.php`:

```bash
php -S localhost:8080 -t examples
open http://localhost:8080/login.php
```

After signing in, `callback.php` will display the user's DID. Copy it — you'll pass it to the CLI examples.

## Running the CLI examples

Pass the DID via env var or first argument:

```bash
BLUESKY_DID=did:plc:abc123 php examples/post-text.php

# or
php examples/feed-walker.php did:plc:abc123
```

Each script restores the persisted session for that DID, so no further browser interaction is needed.

## Why no identifier+password?

The AT Protocol mandates OAuth 2.1 with DPoP. App passwords are deprecated. There is no API endpoint in `gimucco/atproto-php` (or this library) that accepts identifier + password — sessions are minted only by completing the browser OAuth flow. For long-running automation, log in once interactively, then reuse the persisted session indefinitely (tokens auto-refresh).

## Manual smoke tests (real-API)

For verifying releases against a live test account, see [`tests/manual/`](../tests/manual/).
