<?php

declare(strict_types=1);

/**
 * Upload a video and post it. Bluesky processes videos asynchronously
 * — the upload returns a job, which we poll until the resulting blob
 * is ready, then attach it to a post.
 *
 * The shortest path is `Client::postVideo()`, which combines all three
 * steps. The longer form is shown below it for cases where you want
 * to reuse the uploaded blob (post + reply with the same video, etc.)
 * or set unusual post options.
 *
 * Usage:
 *   php examples/video-upload.php <your-did> <path-to-mp4>
 *
 * Blocks while polling — `postVideo()` / `uploadVideo()` wait up to
 * 120s by default. Pass a smaller `timeoutSeconds` in production code
 * that runs under a web-request budget.
 */

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$videoPath = examples_arg(2);
if ($videoPath === null || !is_file($videoPath)) {
	fwrite(STDERR, "Usage: php examples/video-upload.php <did> <path-to-mp4>\n");
	exit(1);
}

$bytes = (string) file_get_contents($videoPath);

// One-shot: upload + await + post in a single call.
echo 'Posting video... ';
$ref = $client->postVideo(
	'Watch this 👀',
	$bytes,
	alt: 'A video clip uploaded via gimucco/bluesky-php',
);
echo "OK ({$ref->uri})\n";

// Longer form when you need the BlobRef for reuse (post + reply with the
// same video, retries, attaching to a recordWithMedia quote, etc.):
//
//   use Gimucco\Bluesky\EmbeddedVideo;
//   $blob = $client->uploadVideo($bytes);                  // returns BlobRef
//   $client->post('caption', video: new EmbeddedVideo($blob, alt: '...'));
//   $client->reply($parent, $cid, 'see this', video: new EmbeddedVideo($blob));
