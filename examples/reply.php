<?php

declare(strict_types=1);

/**
 * Reply to an existing post.
 *
 * Usage:
 *   php examples/reply.php <did> <parent-uri> <parent-cid>
 *
 * Requires a session already created via examples/login.php.
 */

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$parentUri = examples_arg(2);
$parentCid = examples_arg(3);

if ($parentUri === null || $parentCid === null) {
	fwrite(STDERR, "Usage: php examples/reply.php <did> <parent-uri> <parent-cid>\n");
	exit(1);
}

$reply = $client->reply(
	parentUri: $parentUri,
	parentCid: $parentCid,
	text: 'This is a reply from bluesky-php!',
);

echo "Replied: {$reply->uri}\n";
