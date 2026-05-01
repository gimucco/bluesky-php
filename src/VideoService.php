<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Closure;
use Gimucco\Atproto\Session;
use Gimucco\Bluesky\Exception\ApiException;
use Gimucco\Bluesky\Exception\BlueskyException;
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
 * Each call mints a short-lived service-auth JWT via the user's PDS and
 * sends it as a plain Bearer token to the video service. The token's
 * audience (`aud`) is the user's **PDS** DID — `did:web:<pdsHost>` —
 * not the video service's DID. The video service validates the token
 * by checking it was issued by the PDS that hosts the user's account.
 */
final class VideoService
{
	public const DEFAULT_VIDEO_SERVICE_URL = 'https://video.bsky.app';

	/**
	 * Lexicon-defined job states from `app.bsky.video.defs#jobStatus.knownValues`.
	 * Treated as an open string set (the lexicon explicitly leaves room for new
	 * states); only the two terminal values used by the polling loop are exposed.
	 */
	public const JOB_STATE_COMPLETED = 'JOB_STATE_COMPLETED';
	public const JOB_STATE_FAILED = 'JOB_STATE_FAILED';

	/** Connect timeout (S2S, local DNS + TLS handshake should be fast). */
	private const CONNECT_TIMEOUT_SECONDS = 10;
	/** Total timeout for the upload — bytes-on-the-wire only; processing is async. */
	private const UPLOAD_TIMEOUT_SECONDS = 120;
	/** Total timeout for status / limits — small JSON request, should be quick. */
	private const STATUS_TIMEOUT_SECONDS = 30;

	/** @var Closure(string, string, string, ?string, ?string, int): array<string, mixed> */
	private readonly Closure $httpTransport;

	/**
	 * @param Session $session
	 * @param Closure(string, string, string, ?string, ?string, int): array<string, mixed>|null $httpTransport Test seam — defaults to the curl transport.
	 */
	public function __construct(
		private readonly object $session,
		private readonly Server $server,
		private readonly string $videoServiceUrl = self::DEFAULT_VIDEO_SERVICE_URL,
		?Closure $httpTransport = null,
	) {
		$this->httpTransport = $httpTransport ?? self::curlTransport();
	}

	/**
	 * Upload a video to the processing service. Returns an `UploadVideoOutput`
	 * carrying the `jobId` to poll via `getJobStatus()` (or use the higher-level
	 * `Client::uploadVideo()` / `Client::awaitVideo()`).
	 *
	 * Handles two server-side quirks transparently:
	 *
	 * 1. The success response is flat (`{did, jobId, state}`) rather than
	 *    wrapped under `jobStatus`. The lexicon-generated parser expects
	 *    the wrapped form; we normalize before parsing.
	 * 2. HTTP 409 with `error="already_exists"` means the bytes are a
	 *    content-hash dedupe match — Bluesky already processed an identical
	 *    upload (typically from a previous attempt that failed after upload
	 *    but before post creation). The body carries a usable `jobId`; we
	 *    convert it to a successful `UploadVideoOutput` so callers can pass
	 *    the jobId straight to `awaitVideo()` / `getJobStatus()` and reuse
	 *    the existing blob instead of re-uploading.
	 *
	 * @param string $bytes Raw video bytes
	 * @param string $mimeType MIME type — defaults to "video/mp4"
	 *
	 * @throws ApiException On HTTP failure (4xx/5xx), except 409+already_exists.
	 */
	public function uploadVideo(string $bytes, string $mimeType = 'video/mp4'): UploadVideoOutput
	{
		// The video service authorizes uploads as generic `uploadBlob`
		// operations — the JWT's `lxm` must reflect that, not the lexicon's
		// `app.bsky.video.uploadVideo` method name. (`getJobStatus` and
		// `getUploadLimits` use their own lexicon names; only upload
		// re-routes to the repo blob lxm.)
		$token = $this->mintServiceToken('com.atproto.repo.uploadBlob');
		$query = http_build_query([
			'did' => $this->session->did,
			'name' => self::filenameForMime($mimeType),
		]);
		$url = $this->videoServiceUrl.'/xrpc/app.bsky.video.uploadVideo?'.$query;

		try {
			$data = ($this->httpTransport)('POST', $url, $token, $bytes, $mimeType, self::UPLOAD_TIMEOUT_SECONDS);
		} catch (ApiException $e) {
			$jobId = $e->body['jobId'] ?? null;
			if ($e->status === 409 && $e->error === 'already_exists' && \is_string($jobId) && $jobId !== '') {
				$data = [
					'did' => $this->session->did,
					'jobId' => $jobId,
					'state' => \is_string($e->body['state'] ?? null) ? $e->body['state'] : self::JOB_STATE_COMPLETED,
				];
			} else {
				throw $e;
			}
		}

		// Wrap the flat shape `{did, jobId, state}` returned on success into
		// the lexicon's expected `{jobStatus: {...}}` envelope.
		if (!isset($data['jobStatus']) && isset($data['jobId'])) {
			$data = ['jobStatus' => $data];
		}

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

	/**
	 * The service-auth token's `aud` claim must identify the user's PDS
	 * (`did:web:<pdsHost>`), not the video service. The video service
	 * validates that the token was issued by the same PDS hosting the
	 * user's account before accepting the upload. Derive the DID from
	 * the live session's pdsUrl on every call rather than caching, so
	 * a session-restore that updates the PDS URL is picked up.
	 *
	 * @throws BlueskyException If the session's pdsUrl has no host.
	 */
	private function mintServiceToken(string $lxm): string
	{
		$pdsHost = parse_url($this->session->pdsUrl, PHP_URL_HOST);
		if (!\is_string($pdsHost) || $pdsHost === '') {
			throw new BlueskyException('Cannot derive PDS DID from session pdsUrl: '.$this->session->pdsUrl);
		}

		return $this->server->getServiceAuth(
			aud: 'did:web:'.$pdsHost,
			exp: time() + 30,
			lxm: $lxm,
		)->token;
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
				throw new BlueskyException('HTTP method must not be empty');
			}

			$ch = curl_init($url);
			if ($ch === false) {
				throw new BlueskyException('Failed to initialize curl');
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
			// $ch (CurlHandle) is auto-released when it falls out of scope on
			// PHP 8.0+. curl_close() has been a no-op since 8.0 and emits a
			// deprecation in 8.5, so we don't call it.

			if (!\is_string($response)) {
				throw new BlueskyException('Video service request failed: '.$curlError);
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
