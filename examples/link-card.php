<?php

declare(strict_types=1);

/**
 * Post with a link card (the rich preview Bluesky shows when a post contains
 * a URL). The card has a uri, title, description, and an optional thumbnail
 * image — uploaded as a blob just like a regular image.
 *
 * Usage:
 *   php examples/link-card.php <your-did> [thumbnail-image-path]
 *
 * If no thumbnail path is given, the card is posted without one.
 */

use Gimucco\Bluesky\EmbeddedExternal;

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$thumb = null;
$thumbPath = examples_arg(2);
if ($thumbPath !== null && is_file($thumbPath)) {
	$bytes = (string) file_get_contents($thumbPath);
	$thumb = $client->uploadImage($bytes);
	echo "Uploaded thumbnail: {$thumb->link}\n";
}

$ref = $client->post(
	'Worth a read:',
	external: new EmbeddedExternal(
		uri: 'https://atproto.com',
		title: 'AT Protocol',
		description: 'A networking protocol for large-scale distributed social applications.',
		thumb: $thumb,
	),
);

echo "Posted link card: {$ref->uri}\n";
