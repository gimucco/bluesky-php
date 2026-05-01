<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Gimucco\Bluesky\Exception\InvalidArgumentException;

/**
 * A quote-post + media combined embed. Maps to `app.bsky.embed.recordWithMedia`.
 *
 * Exactly one of $images or $video must be provided.
 *
 *     $client->post('My take, with proof:', quoting: new EmbeddedRecordWithMedia(
 *         record: new EmbeddedRecord($postUri, $postCid),
 *         images: [new EmbeddedImage($blob, alt: '...')],
 *     ));
 */
final class EmbeddedRecordWithMedia
{
	/**
	 * @param list<BlobRef|EmbeddedImage>|null $images
	 */
	public function __construct(
		public readonly EmbeddedRecord $record,
		public readonly ?array $images = null,
		public readonly ?EmbeddedVideo $video = null,
	) {
		$hasImages = $images !== null && $images !== [];
		$hasVideo = $video !== null;
		if (!$hasImages && !$hasVideo) {
			throw new InvalidArgumentException('EmbeddedRecordWithMedia requires images OR video');
		}
		if ($hasImages && $hasVideo) {
			throw new InvalidArgumentException('EmbeddedRecordWithMedia accepts images OR video, not both');
		}
		if ($hasImages && \count($images) > 4) {
			throw new InvalidArgumentException('A post can include at most 4 images (got '.\count($images).')');
		}
	}
}
