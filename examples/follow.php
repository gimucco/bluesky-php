<?php

declare(strict_types=1);

/**
 * Resolve a handle and follow them.
 *
 * Usage:
 *   php examples/follow.php <your-did> <handle-or-did-to-follow>
 *
 * Requires a session already created via examples/login.php.
 */

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$target = examples_arg(2);
if ($target === null) {
	fwrite(STDERR, "Usage: php examples/follow.php <your-did> <handle-or-did>\n");
	exit(1);
}

$profile = $client->actor->getProfile($target);
echo "Following {$profile->displayName} (@{$profile->handle})...\n";

$follow = $client->follow($profile->did);
echo "Done: {$follow->uri}\n";
