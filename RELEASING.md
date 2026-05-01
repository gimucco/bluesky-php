# Releasing

How to cut a release of `gimucco/bluesky-php`. Internal documentation —
not part of the public API.

## Pre-flight

Run all gates locally:

```bash
composer validate --strict      # composer.json sanity
composer test                   # PHPUnit (all green)
composer phpstan                # PHPStan level 10 + strict-rules
composer cs-check               # PER-CS 2.0
composer generate-check         # generated code matches committed output
composer audit                  # no vulnerable deps
```

All six must pass. CI runs the same on push.

## 1. Bump the version

Edit `CHANGELOG.md`:

- Move the `[Unreleased]` content under a new `[X.Y.Z] - YYYY-MM-DD` header
- Add a fresh empty `[Unreleased]` section above it

```markdown
## [Unreleased]

(no changes yet)

## [0.2.0] - 2026-06-15
...
```

`composer.json` does NOT need a version field — Composer reads it from git tags.

Follow [SemVer](https://semver.org/):

- **0.x → 1.0.0**: when the public API is considered stable
- **patch (0.1.0 → 0.1.1)**: bug fixes only, no API changes
- **minor (0.1.0 → 0.2.0)**: new features, backward-compatible
- **major (0.1.0 → 1.0.0)**: breaking changes (rare in 0.x — but possible since 0.x is "anything goes")

## 2. Commit and push

```bash
git add CHANGELOG.md
git commit -m "Release 0.2.0"
git push origin main
```

Wait for CI to go green on `main`.

## 3. Tag and push the tag

```bash
git tag -a v0.2.0 -m "Release 0.2.0"
git push origin v0.2.0
```

Use the `v` prefix on tags (matches Packagist convention).

## 4. Create the GitHub release

```bash
gh release create v0.2.0 --title "v0.2.0" --notes-file CHANGELOG.md
```

…or do it via the GitHub web UI. Paste the relevant `CHANGELOG.md` section into
the release notes.

## 5. Verify Packagist picked it up

If the repo is connected to Packagist via webhook, the new version appears
within seconds. Otherwise, log in to https://packagist.org/packages/gimucco/bluesky-php
and click "Update".

Verify:

```bash
composer show gimucco/bluesky-php --available
# Should list the new version
```

## 6. (First release only) Submit to Packagist

If this is the very first release:

1. Sign in at https://packagist.org/
2. Click "Submit"
3. Paste `https://github.com/gimucco/bluesky-php`
4. Configure the GitHub webhook for auto-updates:
   - Repo → Settings → Webhooks → Add webhook
   - URL: `https://packagist.org/api/github?username=YOUR_USERNAME`
   - Secret: from your Packagist profile
   - Content type: `application/json`
   - Trigger on: `push` events

## Troubleshooting

### "Package gimucco/bluesky-php not found"

Packagist hasn't seen the tag yet. Click "Update" on the package page or
check the webhook delivery log on GitHub.

### CI fails on `composer generate-check`

Lexicons changed upstream and the generator output is stale. Run:

```bash
composer sync-lexicons
composer generate
git add src/Generated lexicons/SOURCE.txt lexicons/MANIFEST.txt
git commit -m "Regenerate from upstream lexicons"
```

### "GPL-2.0-or-later" license shows as "unknown" on Packagist

The SPDX identifier in `composer.json` must match exactly. Confirm
`composer.json` has `"license": "GPL-2.0-or-later"` (not `GPLv2`).

## Roadmap (post-0.1)

See [README §Pagination](README.md#pagination) and the v0.2 notes in
CHANGELOG. Common items:

- Retry middleware for `RateLimitException` / transient 5xx
- First-class video polling sugar (`postVideo($bytes, alt: ...)` one-shot helper)
- `EmbeddedExternal` thumbnail auto-upload from a path
- PHP 8.2 readonly classes for value objects (Did, Handle, AtUri, Refs)
