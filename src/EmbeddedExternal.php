<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Gimucco\Bluesky\Exception\InvalidArgumentException;

/**
 * An external link card embed — the rectangular preview shown when a post
 * links to a URL (image + title + description). Maps to `app.bsky.embed.external`.
 *
 * The optional thumbnail must already be uploaded as a blob (use
 * Client::uploadImage() to obtain a BlobRef). Bluesky caps thumbnails at 1MB.
 *
 *     $thumb = $client->uploadImage(file_get_contents('preview.jpg'));
 *     $client->post('Worth a read:', external: new EmbeddedExternal(
 *         uri: 'https://example.com/article',
 *         title: 'Article title',
 *         description: 'Short description shown in the card',
 *         thumb: $thumb,
 *     ));
 *
 * Only http:// and https:// URIs are accepted; other schemes (javascript:,
 * data:, file:, etc.) are rejected at construction time as a defense-in-depth
 * measure even though the server also validates.
 */
final class EmbeddedExternal
{
	public function __construct(
		public readonly string $uri,
		public readonly string $title,
		public readonly string $description,
		public readonly ?BlobRef $thumb = null,
	) {
		if (!str_starts_with($uri, 'http://') && !str_starts_with($uri, 'https://')) {
			throw new InvalidArgumentException(
				'EmbeddedExternal URI must be an http:// or https:// URL, got: '.$uri,
			);
		}
	}
}
