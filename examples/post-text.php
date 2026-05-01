<?php

declare(strict_types=1);

/**
 * Post a simple text post.
 *
 * Usage:
 *   php examples/post-text.php did:plc:yourdid
 *   BLUESKY_DID=did:plc:yourdid php examples/post-text.php
 *
 * Requires a session already created via examples/login.php.
 */

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$post = $client->post('Hello from bluesky-php!');

echo "Posted: {$post->uri}\n";
echo "CID: {$post->cid}\n";
