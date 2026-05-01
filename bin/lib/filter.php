<?php

declare(strict_types=1);

/**
 * Shared lexicon filter helpers. Used by both bin/generate-lexicons (to decide
 * what to generate from) and bin/sync-lexicons (to decide what to persist).
 *
 * The two lists together define what enters this repo:
 *   - $LEXICON_IN_SCOPE_PREFIXES / $LEXICON_IN_SCOPE_EXACT — positive include
 *   - lexicons-skip.php — explicit excludes (overrides the include list)
 */

/** @var array<int, string> Namespace prefixes we generate types/methods for. */
$LEXICON_IN_SCOPE_PREFIXES = [
	'app.bsky.actor.',
	'app.bsky.feed.',
	'app.bsky.graph.',
	'app.bsky.notification.',
	'app.bsky.labeler.',
	'app.bsky.video.',
	'app.bsky.embed.',
	'app.bsky.richtext.',
	'app.bsky.bookmark.',
	'app.bsky.contact.',
	'app.bsky.draft.',
	'app.bsky.ageassurance.',
];

/** @var array<int, string> Specific NSIDs from com.atproto we keep (the rest is out-of-scope). */
$LEXICON_IN_SCOPE_EXACT = [
	'com.atproto.repo.createRecord',
	'com.atproto.repo.getRecord',
	'com.atproto.repo.putRecord',
	'com.atproto.repo.deleteRecord',
	'com.atproto.repo.listRecords',
	'com.atproto.repo.applyWrites',
	'com.atproto.repo.uploadBlob',
	'com.atproto.repo.describeRepo',
	'com.atproto.repo.defs',
	'com.atproto.repo.strongRef',
	'com.atproto.identity.resolveHandle',
	'com.atproto.identity.defs',
	'com.atproto.server.getSession',
	'com.atproto.server.describeServer',
	'com.atproto.server.getServiceAuth',
	'com.atproto.server.defs',
	'com.atproto.label.queryLabels',
	'com.atproto.label.defs',
	'com.atproto.lexicon.schema',
];

/**
 * Check whether an NSID matches any skip pattern (supports trailing `.*`).
 *
 * @param array<int, string> $skipPatterns
 */
function isSkipped(string $nsid, array $skipPatterns): bool
{
	foreach ($skipPatterns as $pattern) {
		if ($pattern === $nsid) {
			return true;
		}
		if (str_ends_with($pattern, '.*')) {
			$prefix = substr($pattern, 0, -2);
			if (str_starts_with($nsid, $prefix.'.') || $nsid === $prefix) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Check whether an NSID is in the project's positive-include scope.
 */
function isInScope(string $nsid): bool
{
	global $LEXICON_IN_SCOPE_PREFIXES, $LEXICON_IN_SCOPE_EXACT;

	foreach ($LEXICON_IN_SCOPE_PREFIXES as $prefix) {
		if (str_starts_with($nsid, $prefix)) {
			return true;
		}
	}

	return in_array($nsid, $LEXICON_IN_SCOPE_EXACT, true);
}

/**
 * Combined filter: returns true if the lexicon should be processed (in-scope and not skipped).
 *
 * @param array<int, string> $skipPatterns
 */
function isLexiconIncluded(string $nsid, array $skipPatterns): bool
{
	if (isSkipped($nsid, $skipPatterns)) {
		return false;
	}
	return isInScope($nsid);
}
