<?php

declare(strict_types=1);

/**
 * Post with an attached image.
 *
 * Usage:
 *   php examples/post-with-image.php did:plc:yourdid path/to/image.jpg
 *
 * Requires a session already created via examples/login.php.
 */

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$imagePath = examples_arg(2) ?? __DIR__.'/sample.jpg';
$imageBytes = @file_get_contents($imagePath);
if ($imageBytes === false) {
	fwrite(STDERR, "Cannot read image at: {$imagePath}\n");
	fwrite(STDERR, "Usage: php examples/post-with-image.php <did> <image-path>\n");
	exit(1);
}

use Gimucco\Bluesky\EmbeddedImage;

$blob = $client->uploadImage($imageBytes);

$post = $client->post(
	text: 'Check out this image!',
	images: [
		// Always include alt text for accessibility — screen readers depend on it.
		new EmbeddedImage($blob, alt: 'A description of the image for screen readers'),
	],
);

echo "Posted with image: {$post->uri}\n";
