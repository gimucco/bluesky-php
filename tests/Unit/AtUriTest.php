<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Unit;

use Gimucco\Bluesky\AtUri;
use Gimucco\Bluesky\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AtUriTest extends TestCase
{
	#[Test]
	public function parsesPostUri(): void
	{
		$uri = new AtUri('at://did:plc:alice/app.bsky.feed.post/abc123');
		self::assertSame('did:plc:alice', $uri->authority);
		self::assertSame('app.bsky.feed.post', $uri->collection);
		self::assertSame('abc123', $uri->rkey);
	}

	#[Test]
	public function castsToString(): void
	{
		$raw = 'at://did:plc:test/app.bsky.graph.follow/key1';
		self::assertSame($raw, (string) new AtUri($raw));
	}

	#[Test]
	public function rejectsMissingScheme(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new AtUri('did:plc:alice/app.bsky.feed.post/abc');
	}

	#[Test]
	public function rejectsMissingPath(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new AtUri('at://did:plc:alice');
	}

	#[Test]
	public function rejectsMissingRkey(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new AtUri('at://did:plc:alice/app.bsky.feed.post/');
	}
}
