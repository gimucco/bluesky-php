<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Unit;

use Gimucco\Bluesky\Did;
use Gimucco\Bluesky\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DidTest extends TestCase
{
	#[Test]
	public function acceptsValidPlcDid(): void
	{
		$did = new Did('did:plc:abc123xyz');
		self::assertSame('did:plc:abc123xyz', $did->value);
		self::assertSame('plc', $did->method());
	}

	#[Test]
	public function acceptsValidWebDid(): void
	{
		$did = new Did('did:web:example.com');
		self::assertSame('web', $did->method());
	}

	#[Test]
	public function castsToString(): void
	{
		$did = new Did('did:plc:test');
		self::assertSame('did:plc:test', (string) $did);
	}

	#[Test]
	public function rejectsMissingPrefix(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new Did('plc:foo:bar');
	}

	#[Test]
	public function rejectsMissingMethod(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new Did('did::abc');
	}

	#[Test]
	public function rejectsMissingIdentifier(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new Did('did:plc:');
	}
}
