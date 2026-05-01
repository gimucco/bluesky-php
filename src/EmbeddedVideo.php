<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

/**
 * A video to embed in a post, with optional alt text for accessibility.
 *
 * The blob must already exist on the PDS — typically obtained from
 * Client::video->uploadVideo() and the resulting job's blob field
 * once processing has completed (poll Video::getJobStatus until
 * state is JOB_STATE_COMPLETED, then read jobStatus->blob).
 *
 *     $job = $client->video->uploadVideo($mp4Bytes);
 *     // ... poll $client->video->getJobStatus($job->jobStatus->jobId) until done ...
 *     $videoBlob = \Gimucco\Bluesky\BlobRef::fromArray(
 *         \Gimucco\Bluesky\Internal\Cast::toArray($completedJob->blob)
 *     );
 *     $client->post('Watch this', video: new EmbeddedVideo($videoBlob, alt: '...'));
 */
final class EmbeddedVideo
{
	public function __construct(
		public readonly BlobRef $blob,
		public readonly string $alt = '',
	) {}
}
