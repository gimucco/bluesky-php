<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Gimucco\Bluesky\Exception\InvalidArgumentException;
use Stringable;

/**
 * A decentralized identifier (DID) — the stable, opaque identifier for an account.
 * Format: did:method:identifier (e.g. did:plc:abc123, did:web:example.com)
 */
final class Did implements Stringable
{
	public readonly string $value;

	public function __construct(string $value)
	{
		if (!str_starts_with($value, 'did:')) {
			throw new InvalidArgumentException('DID must start with "did:": '.$value);
		}
		$parts = explode(':', $value, 3);
		if (\count($parts) !== 3 || $parts[1] === '' || $parts[2] === '') {
			throw new InvalidArgumentException('DID must have format "did:method:identifier": '.$value);
		}
		$this->value = $value;
	}

	public function method(): string
	{
		return explode(':', $this->value, 3)[1];
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
