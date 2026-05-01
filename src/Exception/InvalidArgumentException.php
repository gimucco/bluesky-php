<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Exception;

/**
 * Thrown when client code passes an invalid argument to this library —
 * e.g. a malformed DID, handle, or AT-URI. Distinct from ValidationException,
 * which represents an HTTP 400 response from the API.
 */
final class InvalidArgumentException extends BlueskyException {}
