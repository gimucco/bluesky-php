<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Internal;

use Gimucco\Bluesky\Exception\LexiconException;

/**
 * Internal type-coercion helpers used by generated fromArray() factories.
 *
 * Each helper accepts an optional $field hint that's included in the
 * exception message on type mismatch — e.g. `Cast::toString($v, 'handle')`
 * produces "Field \"handle\": expected string, got null" instead of the
 * context-free "Expected string, got null". Callers that don't care about
 * the field name (hand-rolled callers) can omit it.
 *
 * @internal Not part of the public API.
 */
final class Cast
{
	public static function toString(mixed $value, string $field = ''): string
	{
		if (is_string($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		throw new LexiconException(self::msg('string', $value, $field));
	}

	public static function toInt(mixed $value, string $field = ''): int
	{
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && is_numeric($value)) {
			return (int) $value;
		}
		throw new LexiconException(self::msg('int', $value, $field));
	}

	public static function toBool(mixed $value, string $field = ''): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if ($value === 0 || $value === 1) {
			return (bool) $value;
		}
		if ($value === 'true' || $value === 'false') {
			return $value === 'true';
		}
		throw new LexiconException(self::msg('bool', $value, $field));
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function toArray(mixed $value, string $field = ''): array
	{
		if (is_array($value)) {
			/** @var array<string, mixed> $value */
			return $value;
		}
		throw new LexiconException(self::msg('array', $value, $field));
	}

	/**
	 * @return list<mixed>
	 */
	public static function toList(mixed $value, string $field = ''): array
	{
		if (is_array($value)) {
			return array_values($value);
		}
		throw new LexiconException(self::msg('list', $value, $field));
	}

	private static function msg(string $expected, mixed $value, string $field): string
	{
		$got = get_debug_type($value);
		return $field !== ''
			? sprintf('Field "%s": expected %s, got %s', $field, $expected, $got)
			: sprintf('Expected %s, got %s', $expected, $got);
	}
}
