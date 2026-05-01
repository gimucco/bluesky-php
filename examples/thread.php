<?php

declare(strict_types=1);

/**
 * Post a thread of linked replies — useful when content exceeds Bluesky's
 * 300-grapheme per-post limit.
 *
 * Usage:
 *   php examples/thread.php did:plc:yourdid
 *   BLUESKY_DID=did:plc:yourdid php examples/thread.php
 *
 * To slow down posting (helpful for long threads near rate limits):
 *   $client->setDefaultThreadDelay(2)->thread(...);
 */

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$refs = $client->thread(
	'A thread about why this library exists 🧵',
	'PHP devs targeting Bluesky needed a typed wrapper, but every existing client used app passwords — which Bluesky is deprecating in favor of OAuth 2.1 + DPoP.',
	'gimucco/bluesky-php is built on gimucco/atproto-php (OAuth) and provides convenience methods for posts, threads, embeds, social graph, and more.',
	'It also code-generates types for ~93 Bluesky API endpoints from the lexicons. PHPStan level 10 clean. Try it: composer require gimucco/bluesky-php',
);

echo "Posted thread of ".count($refs)." posts:\n";
foreach ($refs as $i => $ref) {
	echo "  [".($i + 1)."] {$ref->uri}\n";
}
