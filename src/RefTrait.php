<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

/**
 * Shared body for the various Ref value objects (PostRef, FollowRef, etc.).
 * Each is a typed (uri, cid) pair that's Stringable as its uri.
 */
trait RefTrait
{
	public function __construct(
		public readonly string $uri,
		public readonly string $cid,
	) {}

	public function __toString(): string
	{
		return $this->uri;
	}
}
