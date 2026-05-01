<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Internal;

use Gimucco\Bluesky\Exception\ApiException;
use Gimucco\Bluesky\Exception\LexiconException;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared response handling for generated method classes.
 *
 * Every generated method has the same shape: check the status, throw on
 * 4xx/5xx, decode the JSON body for 2xx. Centralizing it here cuts ~600
 * lines from the generated code and means a fix (e.g. richer error
 * decoding) only has to be made once.
 *
 * @internal Used by generated code; not part of the public API.
 */
final class HttpResponse
{
	/**
	 * Decode a JSON object response, throwing the appropriate ApiException
	 * subclass on a 4xx/5xx status. Used by generated methods that return
	 * a typed value object (most of them).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx/5xx status
	 * @throws LexiconException If the body isn't a JSON object
	 */
	public static function decode(ResponseInterface $response): array
	{
		self::throwOnError($response);
		return Cast::toArray(json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
	}

	/**
	 * Check the status and throw on 4xx/5xx without decoding the body. Used
	 * by generated methods that return void (procedures with no output schema).
	 *
	 * @throws ApiException On 4xx/5xx status
	 */
	public static function throwOnError(ResponseInterface $response): void
	{
		$status = $response->getStatusCode();
		if ($status < 400) {
			return;
		}
		$body = json_decode((string) $response->getBody(), true);
		throw ApiException::fromResponse($status, is_array($body) ? Cast::toArray($body) : []);
	}
}
