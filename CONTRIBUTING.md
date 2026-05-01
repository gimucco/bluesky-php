# Contributing

Thank you for your interest in contributing to `gimucco/bluesky-php`!

## Development Setup

```bash
git clone https://github.com/gimucco/bluesky-php.git
cd bluesky-php
composer install
```

## Running Tests

```bash
composer test
```

## Static Analysis

```bash
composer phpstan
```

## Coding Standards

Check for violations:

```bash
composer cs-check
```

Auto-fix:

```bash
composer cs-fix
```

## Code Generation

The `src/Generated/` directory is auto-generated from AT Protocol lexicon JSON files. **Do not edit these files by hand.**

The lexicon JSONs themselves are **not committed** — they live canonically at [`bluesky-social/atproto`](https://github.com/bluesky-social/atproto). The committed `lexicons/SOURCE.txt` records the upstream commit SHA the current generated code was produced from.

To regenerate from the latest upstream:

```bash
# 1. Download lexicons from upstream (idempotent — exits early if up to date and on disk)
composer sync-lexicons

# 2. Regenerate PHP from those lexicons
composer generate

# 3. Verify the output is what's currently committed (used in CI)
composer generate-check
```

When you bump the lexicon SHA, commit both the updated `lexicons/SOURCE.txt` and the resulting changes in `src/Generated/`.

If `composer generate` exits with "No in-scope lexicon files found", run `composer sync-lexicons` first.

## Pull Request Guidelines

- Run the full validation suite before submitting: `composer test && composer phpstan && composer cs-check`
- Keep PRs focused — one concern per PR
- Add tests for new features and bug fixes
- Do not commit changes to `src/Generated/` without regenerating from updated lexicons
