<?php

declare(strict_types=1);

/**
 * Upload a video and post it. Bluesky processes videos asynchronously, so the
 * full flow is: upload bytes → poll the job until ready → embed in a post.
 *
 * Usage:
 *   php examples/video-upload.php <your-did> <path-to-mp4>
 *
 * The script blocks while polling — `awaitVideo()` waits up to 120 seconds by
 * default. Pass a smaller timeout in production code that runs under a
 * web-request budget.
 */

use Gimucco\Bluesky\EmbeddedVideo;

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$videoPath = examples_arg(2);
if ($videoPath === null || !is_file($videoPath)) {
	fwrite(STDERR, "Usage: php examples/video-upload.php <did> <path-to-mp4>\n");
	exit(1);
}

echo "1. Uploading video bytes... ";
$bytes = (string) file_get_contents($videoPath);
$job = $client->video->uploadVideo($bytes);
echo "OK (jobId: {$job->jobStatus->jobId})\n";

echo "2. Awaiting processing (up to 120s)... ";
$videoBlob = $client->awaitVideo($job->jobStatus->jobId);
echo "OK (blob: {$videoBlob->link})\n";

echo "3. Posting with video embed... ";
$ref = $client->post(
	'Watch this 👀',
	video: new EmbeddedVideo($videoBlob, alt: 'A video clip uploaded via gimucco/bluesky-php'),
);
echo "OK ({$ref->uri})\n";
