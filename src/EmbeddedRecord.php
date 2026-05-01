<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Stringable;

/**
 * A quote-post embed — references another Bluesky record (typically another
 * post) by its strong ref (AT-URI + CID). Maps to `app.bsky.embed.record`.
 *
 *     $client->post('Great take 👇', quoting: new EmbeddedRecord(
 *         uri: 'at://did:plc:.../app.bsky.feed.post/abc',
 *         cid: 'bafyrei...',
 *     ));
 *
 * For "quote with images/video", wrap this in EmbeddedRecordWithMedia.
 */
final class EmbeddedRecord
{
	public readonly string $uri;

	public function __construct(
		string|Stringable $uri,
		public readonly string $cid,
	) {
		$this->uri = (string) $uri;
	}
}
