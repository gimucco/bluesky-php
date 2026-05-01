# Manual integration tests

Scripts in this directory exercise the library against a **real Bluesky account**.
They are NOT run by `composer test` — only manually, by you, with credentials.

Use them to:

- Verify wire-shape compatibility with the live API after a Bluesky update
- Smoke-test a release before tagging
- Reproduce bugs reported against real-account behavior

## Setup

1. Generate an OAuth client for your test account (one-time):

   ```bash
   cp ../../examples/config.example.php ./config.php
   # edit config.php with your client_id, redirect_uri, jwks_uri, private_key_path
   openssl ecparam -genkey -name prime256v1 -noout -out private.pem
   ```

2. Complete the browser OAuth flow once via `examples/login.php` to seed the
   session store. You'll get a DID — copy it.

3. Run any harness script:

   ```bash
   BLUESKY_DID=did:plc:yourtestaccount php tests/manual/post-and-delete.php
   ```

## Safety

- `config.php` and `private.pem` are gitignored — they will never be committed
- `tests/manual/storage/` (auto-created session store) is also gitignored
- Each script that creates content also deletes it before exiting (best-effort)
- **Use a throwaway test account, not your real one** — these scripts WILL post
  publicly visible content during the run

## Available scripts

(Add scripts as you write them. Suggested baseline:)

- `post-and-delete.php` — create a text post, verify it exists, delete it
- `image-upload.php` — upload a small image, post it, delete the post
- `video-upload.php` — upload a video, await completion, post it, delete the post
- `thread.php` — post a 3-item thread, delete each item
- `social-roundtrip.php` — follow + unfollow a known DID

## When to run

- Before tagging a release
- After bumping `gimucco/atproto-php` to a new minor version
- When CI is green but you want belt-and-suspenders confidence
