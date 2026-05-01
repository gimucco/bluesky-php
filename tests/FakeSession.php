<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * A minimal test double for Gimucco\Atproto\Session.
 * Since Session is final, we can't mock it. This class provides
 * the same public interface needed by Client (both authenticatedRequest
 * for JSON calls and authenticatedRawRequest for blob uploads).
 */
final class FakeSession
{
	public string $did;
	public string $handle;
	public string $pdsUrl;

	/**
	 * Captured calls. `body` is `array` for JSON (authenticatedRequest)
	 * and `string` for raw uploads (authenticatedRawRequest). For raw
	 * calls, `contentType` holds the explicit Content-Type argument.
	 *
	 * @var list<array{method: string, url: string, body: array<string, mixed>|string, headers: array<string, mixed>, contentType?: string}>
	 */
	public array $requests = [];

	/** @var list<ResponseInterface> */
	private array $responses = [];

	public function __construct(
		string $did = 'did:plc:testuser',
		string $handle = 'test.bsky.social',
		string $pdsUrl = 'https://pds.example.com',
	) {
		$this->did = $did;
		$this->handle = $handle;
		$this->pdsUrl = $pdsUrl;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	public function queueResponse(int $status, array $body): void
	{
		$this->responses[] = new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR));
	}

	public function queueRawResponse(ResponseInterface $response): void
	{
		$this->responses[] = $response;
	}

	/**
	 * @param array<string, mixed> $body
	 * @param array<string, mixed> $headers
	 */
	public function authenticatedRequest(
		string $method,
		string $url,
		array $body = [],
		array $headers = [],
	): ResponseInterface {
		$this->requests[] = [
			'method' => $method,
			'url' => $url,
			'body' => $body,
			'headers' => $headers,
		];

		if ($this->responses !== []) {
			return array_shift($this->responses);
		}

		return new Response(200, [], '{}');
	}

	/**
	 * @param array<string, mixed> $headers
	 */
	public function authenticatedRawRequest(
		string $method,
		string $url,
		string $body,
		string $contentType,
		array $headers = [],
	): ResponseInterface {
		$this->requests[] = [
			'method' => $method,
			'url' => $url,
			'body' => $body,
			'headers' => $headers,
			'contentType' => $contentType,
		];

		if ($this->responses !== []) {
			return array_shift($this->responses);
		}

		return new Response(200, [], '{}');
	}

	/**
	 * @return array{method: string, url: string, body: array<string, mixed>|string, headers: array<string, mixed>, contentType?: string}|null
	 */
	public function lastRequest(): ?array
	{
		if ($this->requests === []) {
			return null;
		}
		return end($this->requests);
	}
}
