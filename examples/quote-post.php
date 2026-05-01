<?php

declare(strict_types=1);

/**
 * Quote another post (with optional images attached via EmbeddedRecordWithMedia).
 *
 * Usage:
 *   php examples/quote-post.php <your-did> <quoted-post-uri> <quoted-post-cid>
 *
 * Example:
 *   php examples/quote-post.php did:plc:yourdid \
 *       at://did:plc:alice/app.bsky.feed.post/abc123 \
 *       bafyreialice123
 */

use Gimucco\Bluesky\EmbeddedRecord;

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$quotedUri = examples_arg(2);
$quotedCid = examples_arg(3);
if ($quotedUri === null || $quotedCid === null) {
	fwrite(STDERR, "Usage: php examples/quote-post.php <your-did> <quoted-uri> <quoted-cid>\n");
	exit(1);
}

$ref = $client->post(
	'Great take 👇',
	quoting: new EmbeddedRecord(uri: $quotedUri, cid: $quotedCid),
);

echo "Quoted post created: {$ref->uri}\n";

// To quote AND attach an image, use EmbeddedRecordWithMedia:
//
//   use Gimucco\Bluesky\EmbeddedImage;
//   use Gimucco\Bluesky\EmbeddedRecordWithMedia;
//
//   $blob = $client->uploadImage(file_get_contents('screenshot.png'));
//   $client->post('My take, with proof:', quoting: new EmbeddedRecordWithMedia(
//       record: new EmbeddedRecord($quotedUri, $quotedCid),
//       images: [new EmbeddedImage($blob, alt: 'Screenshot of the original post')],
//   ));
