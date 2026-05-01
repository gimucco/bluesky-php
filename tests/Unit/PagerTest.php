<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Unit;

use Gimucco\Bluesky\Pager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PagerTest extends TestCase
{
	#[Test]
	public function singlePage(): void
	{
		$pager = Pager::iterate(static fn(?string $cursor): array => [['a', 'b', 'c'], null]);

		self::assertSame(['a', 'b', 'c'], iterator_to_array($pager, false));
	}

	#[Test]
	public function multiplePages(): void
	{
		$pages = [
			[['a', 'b'], 'page2'],
			[['c', 'd'], 'page3'],
			[['e'], null],
		];
		$received = [];

		$pager = Pager::iterate(static function (?string $cursor) use (&$pages, &$received): array {
			$received[] = $cursor;
			/** @var array{0: array<int, string>, 1: ?string} $page */
			$page = array_shift($pages);
			return $page;
		});

		self::assertSame(['a', 'b', 'c', 'd', 'e'], iterator_to_array($pager, false));
		self::assertSame([null, 'page2', 'page3'], $received);
	}

	#[Test]
	public function stopsOnEmptyCursor(): void
	{
		$pages = [
			[['a'], ''],
			[['should not fetch'], null],
		];

		$pager = Pager::iterate(static function (?string $cursor) use (&$pages): array {
			/** @var array{0: array<int, string>, 1: ?string} $page */
			$page = array_shift($pages);
			return $page;
		});

		self::assertSame(['a'], iterator_to_array($pager, false));
	}

	#[Test]
	public function maxItemsStopsEarly(): void
	{
		$pages = [
			[['a', 'b', 'c'], 'next'],
			[['should not fetch'], null],
		];
		$callCount = 0;

		$pager = Pager::iterate(
			static function (?string $cursor) use (&$pages, &$callCount): array {
				$callCount++;
				/** @var array{0: array<int, string>, 1: ?string} $page */
				$page = array_shift($pages);
				return $page;
			},
			maxItems: 2,
		);

		self::assertSame(['a', 'b'], iterator_to_array($pager, false));
		self::assertSame(1, $callCount);
	}

	#[Test]
	public function maxItemsAtPageBoundary(): void
	{
		$pages = [
			[['a', 'b'], 'next'],
			[['c', 'd'], null],
		];

		$pager = Pager::iterate(
			static function (?string $cursor) use (&$pages): array {
				/** @var array{0: array<int, string>, 1: ?string} $page */
				$page = array_shift($pages);
				return $page;
			},
			maxItems: 2,
		);

		self::assertSame(['a', 'b'], iterator_to_array($pager, false));
	}

	#[Test]
	public function emptyResult(): void
	{
		$pager = Pager::iterate(static fn(?string $cursor): array => [[], null]);

		self::assertSame([], iterator_to_array($pager, false));
	}

	#[Test]
	public function yieldsObjects(): void
	{
		$obj1 = new stdClass();
		$obj1->name = 'a';
		$obj2 = new stdClass();
		$obj2->name = 'b';

		$pager = Pager::iterate(static fn(?string $cursor): array => [[$obj1, $obj2], null]);

		$result = iterator_to_array($pager, false);
		self::assertCount(2, $result);
		self::assertSame('a', $result[0]->name);
		self::assertSame('b', $result[1]->name);
	}
}
