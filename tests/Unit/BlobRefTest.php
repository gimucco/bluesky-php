<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Unit;

use Gimucco\Bluesky\BlobRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlobRefTest extends TestCase
{
	#[Test]
	public function fromArray(): void
	{
		$data = [
			'$type' => 'blob',
			'ref' => ['$link' => 'bafkreiaaaa'],
			'mimeType' => 'image/jpeg',
			'size' => 12345,
		];

		$ref = BlobRef::fromArray($data);

		self::assertSame('blob', $ref->type);
		self::assertSame('bafkreiaaaa', $ref->link);
		self::assertSame('image/jpeg', $ref->mimeType);
		self::assertSame(12345, $ref->size);
	}

	#[Test]
	public function toArray(): void
	{
		$ref = new BlobRef('blob', 'image/png', 5000, 'bafkreibbbb');

		$array = $ref->toArray();

		self::assertSame('blob', $array['$type']);
		self::assertSame('bafkreibbbb', $array['ref']['$link']);
		self::assertSame('image/png', $array['mimeType']);
		self::assertSame(5000, $array['size']);
	}

	#[Test]
	public function roundTrip(): void
	{
		$original = new BlobRef('blob', 'video/mp4', 99999, 'bafkreicccc');
		$array = $original->toArray();
		$restored = BlobRef::fromArray($array);

		self::assertSame($original->type, $restored->type);
		self::assertSame($original->link, $restored->link);
		self::assertSame($original->mimeType, $restored->mimeType);
		self::assertSame($original->size, $restored->size);
	}

	#[Test]
	public function fromArrayWithMissingFields(): void
	{
		$ref = BlobRef::fromArray([]);

		self::assertSame('blob', $ref->type);
		self::assertSame('', $ref->link);
		self::assertSame('application/octet-stream', $ref->mimeType);
		self::assertSame(0, $ref->size);
	}
}
