<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Closure;
use Gimucco\Atproto\Session;
use Gimucco\Bluesky\Exception\ApiException;
use Gimucco\Bluesky\Exception\InvalidArgumentException;
use Gimucco\Bluesky\Generated\Methods\Com\Atproto\Server;
use Gimucco\Bluesky\Generated\Types\App\Bsky\Video\GetJobStatusOutput;
use Gimucco\Bluesky\Generated\Types\App\Bsky\Video\GetUploadLimitsOutput;
use Gimucco\Bluesky\Generated\Types\App\Bsky\Video\UploadVideoOutput;
use Gimucco\Bluesky\Internal\Cast;
use JsonException;

/**
 * Routes video operations through the Bluesky video processing service
 * (default: video.bsky.app) instead of the user's PDS.
 *
 * Each call mints a short-lived service-auth JWT via the PDS and sends
 * it as a plain Bearer token to the video service. The service DID
 * (`aud` claim of the JWT) is auto-derived from the configured URL as
 * `did:web:<host>` so a custom `videoServiceUrl` works end-to-end.
 */
final class VideoService
{
	public const DEFAULT_VIDEO_SERVICE_URL = 'https://video.bsky.app';

	/** Connect timeout (S2S, local DNS + TLS handshake should be fast). */
	private const CONNECT_TIMEOUT_SECONDS = 10;
	/** Total timeout for the upload — bytes-on-the-wire only; processing is async. */
	private const UPLOAD_TIMEOUT_SECONDS = 120;
	/** Total timeout for status / limits — small JSON request, should be quick. */
	private const STATUS_TIMEOUT_SECONDS = 30;

	private readonly string $serviceDid;

	/** @var Closure(string, string, string, ?string, ?string, int): array<string, mixed> */
	private readonly Closure $httpTransport;

	/**
	 * @param Session $session
	 * @param Closure(string, string, string, ?string, ?string, int): array<string, mixed>|null $httpTransport Test seam — defaults to the curl transport.
	 *
	 * @throws InvalidArgumentException If `$videoServiceUrl` lacks a host component.
	 */
	public function __construct(
		private readonly object $session,
		private readonly Server $server,
		private readonly string $videoServiceUrl = self::DEFAULT_VIDEO_SERVICE_URL,
		?Closure $httpTransport = null,
	) {
		$this->serviceDid = self::deriveServiceDid($videoServiceUrl);
		$this->httpTransport = $httpTransport ?? self::curlTransport();
	}

	/**
	 * Upload a video to the processing service. Returns an `UploadVideoOutput`
	 * carrying the `jobId` to poll via `getJobStatus()` (or use the higher-level
	 * `Client::uploadVideo()` / `Client::awaitVideo()`).
	 *
	 * @param string $bytes Raw video bytes
	 * @param string $mimeType MIME type — defaults to "video/mp4"
	 *
	 * @throws ApiException On HTTP failure (4xx/5xx)
	 */
	public function uploadVideo(string $bytes, string $mimeType = 'video/mp4'): UploadVideoOutput
	{
		$token = $this->mintServiceToken('app.bsky.video.uploadVideo');
		$query = http_build_query([
			'did' => $this->session->did,
			'name' => self::filenameForMime($mimeType),
		]);
		$url = $this->videoServiceUrl.'/xrpc/app.bsky.video.uploadVideo?'.$query;

		$data = ($this->httpTransport)('POST', $url, $token, $bytes, $mimeType, self::UPLOAD_TIMEOUT_SECONDS);
		return UploadVideoOutput::fromArray($data);
	}

	/**
	 * Get status details for a video processing job.
	 *
	 * @throws ApiException On HTTP failure (4xx/5xx)
	 */
	public function getJobStatus(string $jobId): GetJobStatusOutput
	{
		$token = $this->mintServiceToken('app.bsky.video.getJobStatus');
		$query = http_build_query(['jobId' => $jobId]);
		$url = $this->videoServiceUrl.'/xrpc/app.bsky.video.getJobStatus?'.$query;

		$data = ($this->httpTransport)('GET', $url, $token, null, null, self::STATUS_TIMEOUT_SECONDS);
		return GetJobStatusOutput::fromArray($data);
	}

	/**
	 * Get video upload limits for the authenticated user.
	 *
	 * @throws ApiException On HTTP failure (4xx/5xx)
	 */
	public function getUploadLimits(): GetUploadLimitsOutput
	{
		$token = $this->mintServiceToken('app.bsky.video.getUploadLimits');
		$url = $this->videoServiceUrl.'/xrpc/app.bsky.video.getUploadLimits';

		$data = ($this->httpTransport)('GET', $url, $token, null, null, self::STATUS_TIMEOUT_SECONDS);
		return GetUploadLimitsOutput::fromArray($data);
	}

	private function mintServiceToken(string $lxm): string
	{
		return $this->server->getServiceAuth(
			aud: $this->serviceDid,
			exp: time() + 30,
			lxm: $lxm,
		)->token;
	}

	/**
	 * Derive the service DID from the URL's host as `did:web:<host>` —
	 * matches how Bluesky publishes its service identifier and lets a
	 * custom `videoServiceUrl` work without requiring a separate DID arg.
	 *
	 * @throws InvalidArgumentException If the URL has no host component.
	 */
	private static function deriveServiceDid(string $url): string
	{
		$host = parse_url($url, PHP_URL_HOST);
		if (!\is_string($host) || $host === '') {
			throw new InvalidArgumentException('videoServiceUrl must include a host: '.$url);
		}
		return 'did:web:'.$host;
	}

	/**
	 * The video service uses the `?name=` query param for log/debug display
	 * only — it doesn't affect storage. Map common video MIME types to a
	 * sensible filename so logs are readable; fall back to "video.bin" for
	 * anything we don't recognize.
	 */
	private static function filenameForMime(string $mimeType): string
	{
		return match ($mimeType) {
			'video/mp4' => 'video.mp4',
			'video/webm' => 'video.webm',
			'video/quicktime' => 'video.mov',
			'video/mpeg' => 'video.mpeg',
			default => 'video.bin',
		};
	}

	/**
	 * @return Closure(string, string, string, ?string, ?string, int): array<string, mixed>
	 */
	private static function curlTransport(): Closure
	{
		return static function (string $method, string $url, string $token, ?string $body, ?string $contentType, int $timeoutSeconds): array {
			if ($method === '') {
				throw new Exception\BlueskyException('HTTP method must not be empty');
			}

			$ch = curl_init($url);
			if ($ch === false) {
				throw new Exception\BlueskyException('Failed to initialize curl');
			}

			$headers = ['Authorization: Bearer '.$token];
			if ($contentType !== null) {
				$headers[] = 'Content-Type: '.$contentType;
			}

			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
			// Defaults are already true for SSL_VERIFY{PEER,HOST} and false for
			// FOLLOWLOCATION — explicit here to make the security intent obvious
			// to readers and resilient to weird local php.ini overrides.
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

			if ($body !== null) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			}

			$response = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curlError = curl_error($ch);
			curl_close($ch);

			if (!\is_string($response)) {
				throw new Exception\BlueskyException('Video service request failed: '.$curlError);
			}

			// Decode defensively: a 502 from a CDN upstream is often HTML, not
			// JSON. Wrap json_decode in a try so we can still raise the right
			// ApiException subclass with whatever status we got.
			try {
				$decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
			} catch (JsonException) {
				$decoded = null;
			}

			if ($httpCode >= 400) {
				throw ApiException::fromResponse(
					$httpCode,
					\is_array($decoded) ? Cast::toArray($decoded) : [],
				);
			}

			if (!\is_array($decoded)) {
				throw new Exception\LexiconException(
					'Expected JSON object from video service; got: '.substr($response, 0, 200),
				);
			}

			return Cast::toArray($decoded);
		};
	}
}
