<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Gimucco\Bluesky\Internal\Cast;

final class BlobRef
{
	public function __construct(
		public readonly string $type,
		public readonly string $mimeType,
		public readonly int $size,
		public readonly string $link,
	) {}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		$ref = isset($data['ref']) ? Cast::toArray($data['ref']) : [];
		return new self(
			type: Cast::toString($data['$type'] ?? 'blob'),
			mimeType: Cast::toString($data['mimeType'] ?? 'application/octet-stream'),
			size: Cast::toInt($data['size'] ?? 0),
			link: Cast::toString($ref['$link'] ?? ''),
		);
	}

	/**
	 * @return array{'$type': string, ref: array{'$link': string}, mimeType: string, size: int}
	 */
	public function toArray(): array
	{
		return [
			'$type' => $this->type,
			'ref' => ['$link' => $this->link],
			'mimeType' => $this->mimeType,
			'size' => $this->size,
		];
	}
}
