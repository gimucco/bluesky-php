<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Exception;

use Throwable;

class ApiException extends BlueskyException
{
	/**
	 * @param array<string, mixed> $body Raw decoded response body, when available.
	 *        Useful for endpoints whose error responses carry application-level
	 *        signals (e.g. video.bsky.app's 409 returns a `jobId` field that the
	 *        caller can pass to `getJobStatus()` to recover the existing blob).
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $error,
		string $message,
		public readonly array $body = [],
		?Throwable $previous = null,
	) {
		parent::__construct($message, $status, $previous);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	public static function fromResponse(int $status, array $body): self
	{
		$error = \is_string($body['error'] ?? null) ? $body['error'] : 'Unknown';
		// Fall back to "<error> (HTTP <status>)" when the body has no message
		// field — the video service often returns error+jobId without one.
		// This way `$e->getMessage()` is informative without forcing callers
		// to also inspect $e->status / $e->error.
		$message = \is_string($body['message'] ?? null)
			? $body['message']
			: $error.' (HTTP '.$status.')';

		return match (true) {
			$status === 404 => new NotFoundException($status, $error, $message, $body),
			$status === 429 => RateLimitException::fromBody($status, $error, $message, $body),
			$status === 400 => new ValidationException($status, $error, $message, $body),
			$status === 401, $status === 403 => new AuthException($status, $error, $message, $body),
			$status >= 500 => new ServerException($status, $error, $message, $body),
			default => new self($status, $error, $message, $body),
		};
	}
}
