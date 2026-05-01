<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Exception;

use Throwable;

class ApiException extends BlueskyException
{
	public function __construct(
		public readonly int $status,
		public readonly string $error,
		string $message,
		?Throwable $previous = null,
	) {
		parent::__construct($message, $status, $previous);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	public static function fromResponse(int $status, array $body): self
	{
		$error = is_string($body['error'] ?? null) ? $body['error'] : 'Unknown';
		$message = is_string($body['message'] ?? null) ? $body['message'] : 'Unknown error';

		return match (true) {
			$status === 404 => new NotFoundException($status, $error, $message),
			$status === 429 => RateLimitException::fromBody($status, $error, $message, $body),
			$status === 400 => new ValidationException($status, $error, $message),
			$status === 401, $status === 403 => new AuthException($status, $error, $message),
			$status >= 500 => new ServerException($status, $error, $message),
			default => new self($status, $error, $message),
		};
	}
}
