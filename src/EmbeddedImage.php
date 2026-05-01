<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

/**
 * An image to embed in a post, with alt text for accessibility.
 *
 * Bluesky strongly encourages alt text on every image; screen readers and
 * search rely on it. Pass an EmbeddedImage instead of a bare BlobRef to
 * Client::post() / Client::reply() to set per-image alt text:
 *
 *     $blob = $client->uploadImage($bytes);
 *     $client->post('Sunset', images: [
 *         new EmbeddedImage($blob, alt: 'Orange sun setting over a calm sea'),
 *     ]);
 */
final class EmbeddedImage
{
	public function __construct(
		public readonly BlobRef $blob,
		public readonly string $alt = '',
	) {}
}
