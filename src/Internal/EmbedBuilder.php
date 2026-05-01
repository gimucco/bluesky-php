<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Internal;

use Gimucco\Bluesky\BlobRef;
use Gimucco\Bluesky\EmbeddedExternal;
use Gimucco\Bluesky\EmbeddedImage;
use Gimucco\Bluesky\EmbeddedRecord;
use Gimucco\Bluesky\EmbeddedRecordWithMedia;
use Gimucco\Bluesky\EmbeddedVideo;
use Gimucco\Bluesky\Exception\InvalidArgumentException;
use LogicException;

/**
 * Builds the `embed` payload for `app.bsky.feed.post` records.
 *
 * Bluesky posts can carry **at most one** of the five lexicon embed types
 * (`images`, `video`, `external`, `record`, `recordWithMedia`); this class
 * enforces that, validates the per-embed lexicon limits, and produces the
 * exact wire shape the API expects.
 *
 * Extracted from Client to keep the facade focused on HTTP plumbing.
 *
 * @internal Used by Client; not part of the public API surface, but the
 *           output array shapes are guaranteed by the Bluesky lexicon spec.
 */
final class EmbedBuilder
{
	/** Bluesky lexicon cap on app.bsky.embed.images. */
	public const MAX_IMAGES_PER_POST = 4;

	/**
	 * Build the embed payload from at most one of: images, video, external
	 * link card, or quoting (which itself may carry media via
	 * EmbeddedRecordWithMedia). Returns null if no embed is requested.
	 *
	 * @param list<BlobRef|EmbeddedImage>|null $images
	 * @return array<string, mixed>|null
	 *
	 * @throws InvalidArgumentException If more than one embed type is set, or
	 *         if the images list exceeds the lexicon cap of 4.
	 */
	public function build(
		?array $images,
		?EmbeddedVideo $video,
		?EmbeddedExternal $external,
		EmbeddedRecord|EmbeddedRecordWithMedia|null $quoting,
	): ?array {
		// Named flags so the rejection error tells the caller exactly which
		// embed types collided.
		$present = array_keys(array_filter([
			'images' => $images !== null && $images !== [],
			'video' => $video !== null,
			'external' => $external !== null,
			'quoting' => $quoting !== null,
		]));

		if (count($present) > 1) {
			throw new InvalidArgumentException(
				'A post can carry at most one embed; got: '.implode(', ', $present),
			);
		}

		// The `?? throw` here is a PHPStan satisfier — match() doesn't narrow
		// the union types via the $present[0] check, so we re-assert what we
		// already know. If any of these throws fires, EmbedBuilder has a bug
		// (a key in $present that doesn't match one of the four arms).
		return match ($present[0] ?? null) {
			'images' => $this->images($images ?? throw new LogicException('EmbedBuilder: images null after positive flag')),
			'video' => $this->video($video ?? throw new LogicException('EmbedBuilder: video null after positive flag')),
			'external' => $this->external($external ?? throw new LogicException('EmbedBuilder: external null after positive flag')),
			'quoting' => $this->record($quoting ?? throw new LogicException('EmbedBuilder: quoting null after positive flag')),
			default => null,
		};
	}

	/**
	 * @param list<BlobRef|EmbeddedImage> $images
	 * @return array<string, mixed>
	 */
	private function images(array $images): array
	{
		if (count($images) > self::MAX_IMAGES_PER_POST) {
			throw new InvalidArgumentException(
				'A post can include at most '.self::MAX_IMAGES_PER_POST.' images (got '.count($images).')',
			);
		}
		$imageRecords = [];
		foreach ($images as $image) {
			if ($image instanceof EmbeddedImage) {
				$imageRecords[] = ['alt' => $image->alt, 'image' => $image->blob->toArray()];
			} else {
				$imageRecords[] = ['alt' => '', 'image' => $image->toArray()];
			}
		}

		return [
			'$type' => 'app.bsky.embed.images',
			'images' => $imageRecords,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function video(EmbeddedVideo $video): array
	{
		$embed = [
			'$type' => 'app.bsky.embed.video',
			'video' => $video->blob->toArray(),
		];
		// alt is optional in the lexicon; only include when set.
		if ($video->alt !== '') {
			$embed['alt'] = $video->alt;
		}
		return $embed;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function external(EmbeddedExternal $external): array
	{
		$ext = [
			'uri' => $external->uri,
			'title' => $external->title,
			'description' => $external->description,
		];
		if ($external->thumb !== null) {
			$ext['thumb'] = $external->thumb->toArray();
		}
		return [
			'$type' => 'app.bsky.embed.external',
			'external' => $ext,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function record(EmbeddedRecord|EmbeddedRecordWithMedia $quoting): array
	{
		if ($quoting instanceof EmbeddedRecord) {
			return [
				'$type' => 'app.bsky.embed.record',
				'record' => ['uri' => $quoting->uri, 'cid' => $quoting->cid],
			];
		}

		// EmbeddedRecordWithMedia's constructor enforces "exactly one of
		// images / video" — so if images is null, video must be set.
		$media = $quoting->images !== null
			? $this->images($quoting->images)
			: $this->video($quoting->video ?? throw new LogicException(
				'EmbeddedRecordWithMedia invariant violated: neither images nor video',
			));

		return [
			'$type' => 'app.bsky.embed.recordWithMedia',
			'record' => [
				'$type' => 'app.bsky.embed.record',
				'record' => ['uri' => $quoting->record->uri, 'cid' => $quoting->record->cid],
			],
			'media' => $media,
		];
	}
}
