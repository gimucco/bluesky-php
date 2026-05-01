<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Unit;

use Gimucco\Bluesky\Exception\ApiException;
use Gimucco\Bluesky\Exception\AuthException;
use Gimucco\Bluesky\Exception\NotFoundException;
use Gimucco\Bluesky\Exception\RateLimitException;
use Gimucco\Bluesky\Exception\ServerException;
use Gimucco\Bluesky\Exception\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExceptionMappingTest extends TestCase
{
	#[Test]
	public function notFound(): void
	{
		$e = ApiException::fromResponse(404, ['error' => 'NotFound', 'message' => 'Record not found']);

		self::assertInstanceOf(NotFoundException::class, $e);
		self::assertSame(404, $e->status);
		self::assertSame('NotFound', $e->error);
		self::assertSame('Record not found', $e->getMessage());
	}

	#[Test]
	public function rateLimit(): void
	{
		$e = ApiException::fromResponse(429, ['error' => 'RateLimitExceeded', 'message' => 'Too many requests']);

		self::assertInstanceOf(RateLimitException::class, $e);
		self::assertSame(429, $e->status);
		self::assertNull($e->retryAfter);
	}

	#[Test]
	public function validation(): void
	{
		$e = ApiException::fromResponse(400, ['error' => 'InvalidRequest', 'message' => 'Bad input']);

		self::assertInstanceOf(ValidationException::class, $e);
		self::assertSame(400, $e->status);
	}

	#[Test]
	public function auth401(): void
	{
		$e = ApiException::fromResponse(401, ['error' => 'AuthRequired', 'message' => 'Authentication required']);

		self::assertInstanceOf(AuthException::class, $e);
		self::assertSame(401, $e->status);
	}

	#[Test]
	public function auth403(): void
	{
		$e = ApiException::fromResponse(403, ['error' => 'Forbidden', 'message' => 'Access denied']);

		self::assertInstanceOf(AuthException::class, $e);
		self::assertSame(403, $e->status);
	}

	#[Test]
	public function serverError(): void
	{
		$e = ApiException::fromResponse(500, ['error' => 'InternalServerError', 'message' => 'Server error']);

		self::assertInstanceOf(ServerException::class, $e);
		self::assertSame(500, $e->status);
	}

	public function test502(): void
	{
		$e = ApiException::fromResponse(502, ['error' => 'BadGateway', 'message' => 'Bad gateway']);

		self::assertInstanceOf(ServerException::class, $e);
	}

	#[Test]
	public function unknownStatus(): void
	{
		$e = ApiException::fromResponse(418, ['error' => 'Teapot', 'message' => 'I am a teapot']);

		self::assertInstanceOf(ApiException::class, $e);
		self::assertNotInstanceOf(NotFoundException::class, $e);
		self::assertNotInstanceOf(ServerException::class, $e);
	}

	#[Test]
	public function missingFields(): void
	{
		$e = ApiException::fromResponse(500, []);

		self::assertInstanceOf(ServerException::class, $e);
		self::assertSame('Unknown', $e->error);
		// When the body has no `message`, fall back to "<error> (HTTP <status>)"
		// so $e->getMessage() is informative on its own — the video service
		// often returns error+jobId without a message field.
		self::assertSame('Unknown (HTTP 500)', $e->getMessage());
		self::assertSame([], $e->body);
	}

	#[Test]
	public function bodyIsExposedForApplicationLevelSignals(): void
	{
		// The video service returns 409 with a usable jobId for content-hash
		// dedupe — callers reach into ->body to recover application state.
		$e = ApiException::fromResponse(409, [
			'did' => 'did:plc:testuser',
			'error' => 'already_exists',
			'jobId' => 'abc123',
			'state' => 'JOB_STATE_COMPLETED',
		]);

		self::assertSame('already_exists', $e->error);
		self::assertSame('abc123', $e->body['jobId']);
		self::assertSame('JOB_STATE_COMPLETED', $e->body['state']);
	}
}
