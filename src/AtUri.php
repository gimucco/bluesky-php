<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Gimucco\Bluesky\Exception\InvalidArgumentException;
use Stringable;

/**
 * An AT-URI: at://{authority}/{collection}/{rkey}
 * The authority is a DID (or, less commonly, a handle).
 */
final class AtUri implements Stringable
{
	public readonly string $value;
	public readonly string $authority;
	public readonly string $collection;
	public readonly string $rkey;

	public function __construct(string $value)
	{
		if (!str_starts_with($value, 'at://')) {
			throw new InvalidArgumentException('AT-URI must start with "at://": '.$value);
		}
		$path = substr($value, 5);
		$parts = explode('/', $path);
		if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
			throw new InvalidArgumentException('AT-URI must have format "at://authority/collection/rkey": '.$value);
		}
		$this->value = $value;
		$this->authority = $parts[0];
		$this->collection = $parts[1];
		$this->rkey = $parts[2];
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
