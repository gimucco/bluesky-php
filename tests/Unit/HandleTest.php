<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Unit;

use Gimucco\Bluesky\Exception\InvalidArgumentException;
use Gimucco\Bluesky\Handle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandleTest extends TestCase
{
	#[Test]
	public function acceptsBskyHandle(): void
	{
		$h = new Handle('alice.bsky.social');
		self::assertSame('alice.bsky.social', $h->value);
	}

	#[Test]
	public function acceptsCustomDomain(): void
	{
		$h = new Handle('andrea.olivato.me');
		self::assertSame('andrea.olivato.me', $h->value);
	}

	#[Test]
	public function stripsLeadingAt(): void
	{
		$h = new Handle('@alice.bsky.social');
		self::assertSame('alice.bsky.social', $h->value);
	}

	#[Test]
	public function castsToString(): void
	{
		self::assertSame('foo.test', (string) new Handle('foo.test'));
	}

	#[Test]
	public function rejectsHandleWithoutDot(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new Handle('justaword');
	}

	#[Test]
	public function rejectsEmpty(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new Handle('');
	}

	#[Test]
	public function rejectsInvalidChars(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new Handle('alice space.test');
	}
}
