<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Generator;

final class Pager
{
	/**
	 * Iterate over a cursor-paginated endpoint, yielding one item at a time.
	 *
	 * The fetch closure receives the current cursor (null on first call) and
	 * returns a tuple of [items, nextCursor]. Iteration stops when nextCursor
	 * is null or empty, or when $maxItems is reached.
	 *
	 * Most users should reach for the generated `paginate*` methods instead
	 * (e.g. `$client->feed->paginateTimeline()`); this helper is for endpoints
	 * with custom shapes or for composing pagination across multiple sources.
	 *
	 * Example:
	 *     $timeline = Pager::iterate(function (?string $cursor) use ($client): array {
	 *         $resp = $client->feed->getTimeline(cursor: $cursor, limit: 100);
	 *         return [$resp->feed, $resp->cursor];
	 *     });
	 *     foreach ($timeline as $item) { ... }
	 *
	 * @template T
	 * @param callable(?string $cursor): array{0: list<T>, 1: ?string} $fetch
	 * @param int|null $maxItems Stop after yielding this many items
	 * @return Generator<int, T>
	 */
	public static function iterate(callable $fetch, ?int $maxItems = null): Generator
	{
		$cursor = null;
		$count = 0;
		do {
			[$items, $cursor] = $fetch($cursor);
			foreach ($items as $item) {
				yield $item;
				$count++;
				if ($maxItems !== null && $count >= $maxItems) {
					return;
				}
			}
		} while ($cursor !== null && $cursor !== '');
	}
}
