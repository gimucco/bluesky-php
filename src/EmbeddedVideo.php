<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

/**
 * A video to embed in a post, with optional alt text for accessibility.
 *
 * The blob must already exist on Bluesky's video processing service —
 * typically obtained via `Client::uploadVideo()`, which uploads bytes
 * and waits for processing to finish before returning the BlobRef:
 *
 *     $blob = $client->uploadVideo(file_get_contents('clip.mp4'));
 *     $client->post('Watch this', video: new EmbeddedVideo($blob, alt: '...'));
 *
 * For the simple "post a video" case, `Client::postVideo()` combines
 * upload + post into a single call.
 */
final class EmbeddedVideo
{
	public function __construct(
		public readonly BlobRef $blob,
		public readonly string $alt = '',
	) {}
}
