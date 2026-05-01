<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Gimucco\Bluesky\Exception\InvalidArgumentException;
use Stringable;

/**
 * A human-readable handle, e.g. "alice.bsky.social".
 * Must contain at least one dot and be a valid DNS-style identifier.
 */
final class Handle implements Stringable
{
	public readonly string $value;

	public function __construct(string $value)
	{
		$normalized = ltrim($value, '@');
		if ($normalized === '' || !str_contains($normalized, '.')) {
			throw new InvalidArgumentException('Handle must be a domain-style identifier: '.$value);
		}
		if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?\.[a-zA-Z]{2,}$/', $normalized) !== 1) {
			throw new InvalidArgumentException('Handle has invalid characters: '.$value);
		}
		$this->value = $normalized;
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
