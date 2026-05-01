<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Exception;

use DateTimeImmutable;
use DateTimeInterface;

final class RateLimitException extends ApiException
{
	public readonly ?DateTimeImmutable $retryAfter;

	/**
	 * @param array<string, mixed> $body
	 */
	private function __construct(int $status, string $error, string $message, array $body, ?DateTimeImmutable $retryAfter)
	{
		parent::__construct($status, $error, $message, $body);
		$this->retryAfter = $retryAfter;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	public static function fromBody(int $status, string $error, string $message, array $body): self
	{
		$retryAfter = $body['retryAfter'] ?? null;
		$parsed = null;
		if (\is_string($retryAfter)) {
			$dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $retryAfter);
			if ($dt === false) {
				$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $retryAfter);
			}
			$parsed = $dt !== false ? $dt : null;
		} elseif (\is_int($retryAfter)) {
			$parsed = (new DateTimeImmutable())->modify('+'.$retryAfter.' seconds');
		}

		return new self($status, $error, $message, $body, $parsed);
	}
}
