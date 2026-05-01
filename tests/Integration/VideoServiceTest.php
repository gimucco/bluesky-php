<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Integration;

use Closure;
use Gimucco\Bluesky\Exception\ApiException;
use Gimucco\Bluesky\Exception\AuthException;
use Gimucco\Bluesky\Exception\BlueskyException;
use Gimucco\Bluesky\Generated\Methods\Com\Atproto\Server;
use Gimucco\Bluesky\Tests\FakeSession;
use Gimucco\Bluesky\VideoService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VideoServiceTest extends TestCase
{
	/** @var list<array{method: string, url: string, token: string, body: ?string, contentType: ?string, timeoutSeconds: int}> */
	private array $transportRequests = [];

	private function fakeTransport(mixed $response): Closure
	{
		return function (string $method, string $url, string $token, ?string $body, ?string $contentType, int $timeoutSeconds) use ($response): array {
			$this->transportRequests[] = compact('method', 'url', 'token', 'body', 'contentType', 'timeoutSeconds');
			return $response;
		};
	}

	private function buildService(FakeSession $session, ?Closure $transport = null): VideoService
	{
		return new VideoService($session, new Server($session), httpTransport: $transport);
	}

	#[Test]
	public function uploadVideoMintsServiceAuthBoundToPdsAndRoutesToVideoService(): void
	{
		$session = new FakeSession();   // pdsUrl = https://pds.example.com
		$session->queueResponse(200, ['token' => 'jwt-upload']);

		// Real video service returns a flat shape on success.
		$service = $this->buildService($session, $this->fakeTransport([
			'did' => 'did:plc:testuser',
			'jobId' => 'j1',
			'state' => 'JOB_STATE_CREATED',
		]));
		$result = $service->uploadVideo('VIDEO_BYTES');

		self::assertSame('j1', $result->jobStatus->jobId);
		self::assertSame('JOB_STATE_CREATED', $result->jobStatus->state);

		$authReq = $session->requests[0];
		self::assertStringContainsString('com.atproto.server.getServiceAuth', $authReq['url']);
		// aud is the user's PDS DID (derived from session->pdsUrl host),
		// NOT the video service DID.
		self::assertStringContainsString('aud=did%3Aweb%3Apds.example.com', $authReq['url']);
		// lxm for upload is the generic uploadBlob, not app.bsky.video.uploadVideo.
		self::assertStringContainsString('lxm=com.atproto.repo.uploadBlob', $authReq['url']);

		self::assertCount(1, $this->transportRequests);
		$req = $this->transportRequests[0];
		self::assertSame('POST', $req['method']);
		self::assertStringStartsWith('https://video.bsky.app/xrpc/app.bsky.video.uploadVideo', $req['url']);
		self::assertStringContainsString('did=did%3Aplc%3Atestuser', $req['url']);
		self::assertStringContainsString('name=video.mp4', $req['url']);
		self::assertSame('jwt-upload', $req['token']);
		self::assertSame('VIDEO_BYTES', $req['body']);
		self::assertSame('video/mp4', $req['contentType']);
	}

	#[Test]
	public function uploadVideoNormalizesFlatResponseShape(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt']);

		// Live video service returns this shape — NOT wrapped under jobStatus.
		$flat = ['did' => 'did:plc:testuser', 'jobId' => 'd7q9d8838n5c72ukj3bg', 'state' => 'JOB_STATE_CREATED'];
		$service = $this->buildService($session, $this->fakeTransport($flat));
		$result = $service->uploadVideo('bytes');

		self::assertSame('d7q9d8838n5c72ukj3bg', $result->jobStatus->jobId);
		self::assertSame('JOB_STATE_CREATED', $result->jobStatus->state);
		self::assertSame('did:plc:testuser', $result->jobStatus->did);
	}

	#[Test]
	public function uploadVideoTreats409AlreadyExistsAsSuccess(): void
	{
		// The video service deduplicates uploads by content hash. A 409
		// with `already_exists` carries a usable jobId — the caller can
		// pass it to awaitVideo() / getJobStatus() to reuse the existing
		// blob instead of re-uploading.
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt']);

		$transport = static function (): array {
			throw ApiException::fromResponse(409, [
				'did' => 'did:plc:testuser',
				'error' => 'already_exists',
				'jobId' => 'd7q9es6ruh9s72r9covg',
				'message' => 'Video already processed',
				'state' => 'JOB_STATE_COMPLETED',
			]);
		};

		$service = $this->buildService($session, $transport);
		$result = $service->uploadVideo('bytes');

		self::assertSame('d7q9es6ruh9s72r9covg', $result->jobStatus->jobId);
		self::assertSame('JOB_STATE_COMPLETED', $result->jobStatus->state);
	}

	#[Test]
	public function uploadVideoStillThrowsOn409WithoutJobId(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt']);

		// 409 without a jobId is a real conflict, not a dedupe — still surface.
		$transport = static function (): array {
			throw ApiException::fromResponse(409, ['error' => 'conflict', 'message' => 'something else']);
		};

		$service = $this->buildService($session, $transport);
		$this->expectException(ApiException::class);
		$service->uploadVideo('bytes');
	}

	#[Test]
	public function uploadVideoStillThrowsOn409WithEmptyJobId(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt']);

		// Defensive: an empty-string jobId in the body is not a usable signal —
		// passing it to awaitVideo() would call getJobStatus('') and 404.
		$transport = static function (): array {
			throw ApiException::fromResponse(409, ['error' => 'already_exists', 'jobId' => '']);
		};

		$service = $this->buildService($session, $transport);
		$this->expectException(ApiException::class);
		$service->uploadVideo('bytes');
	}

	#[Test]
	public function uploadVideoSurfacesUnrelatedApiErrors(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt']);

		$transport = static function (): array {
			throw ApiException::fromResponse(401, ['error' => 'invalid token']);
		};

		$service = $this->buildService($session, $transport);
		$this->expectException(AuthException::class);
		$service->uploadVideo('bytes');
	}

	#[Test]
	public function getJobStatusMintsPerMethodServiceAuth(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt-status']);

		$service = $this->buildService($session, $this->fakeTransport([
			'jobStatus' => ['jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_COMPLETED',
				'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkrei'], 'mimeType' => 'video/mp4', 'size' => 1]],
		]));
		$result = $service->getJobStatus('j1');

		self::assertSame('JOB_STATE_COMPLETED', $result->jobStatus->state);

		$authReq = $session->requests[0];
		self::assertStringContainsString('aud=did%3Aweb%3Apds.example.com', $authReq['url']);
		self::assertStringContainsString('lxm=app.bsky.video.getJobStatus', $authReq['url']);

		self::assertCount(1, $this->transportRequests);
		$req = $this->transportRequests[0];
		self::assertSame('GET', $req['method']);
		self::assertStringStartsWith('https://video.bsky.app/xrpc/app.bsky.video.getJobStatus', $req['url']);
		self::assertStringContainsString('jobId=j1', $req['url']);
		self::assertSame('jwt-status', $req['token']);
		self::assertNull($req['body']);
	}

	#[Test]
	public function getUploadLimitsMintsPerMethodServiceAuth(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt-limits']);

		$service = $this->buildService($session, $this->fakeTransport([
			'canUpload' => true,
			'remainingDailyVideos' => 25,
			'remainingDailyBytes' => 10_000_000,
		]));
		$result = $service->getUploadLimits();

		self::assertTrue($result->canUpload);
		self::assertSame(25, $result->remainingDailyVideos);

		$authReq = $session->requests[0];
		self::assertStringContainsString('aud=did%3Aweb%3Apds.example.com', $authReq['url']);
		self::assertStringContainsString('lxm=app.bsky.video.getUploadLimits', $authReq['url']);

		self::assertCount(1, $this->transportRequests);
		$req = $this->transportRequests[0];
		self::assertSame('GET', $req['method']);
		self::assertSame('https://video.bsky.app/xrpc/app.bsky.video.getUploadLimits', $req['url']);
		self::assertSame('jwt-limits', $req['token']);
	}

	#[Test]
	public function customVideoServiceUrlRoutesToCustomHost(): void
	{
		// Custom URL only changes the routing target. The aud claim still
		// identifies the user's PDS, regardless of where the upload goes.
		$session = new FakeSession(pdsUrl: 'https://rhizopogon.us-west.host.bsky.network');
		$session->queueResponse(200, ['token' => 'jwt-custom']);

		$transport = $this->fakeTransport(['canUpload' => true]);
		$service = new VideoService($session, new Server($session), 'https://custom-video.example.com', $transport);
		$service->getUploadLimits();

		self::assertSame('https://custom-video.example.com/xrpc/app.bsky.video.getUploadLimits', $this->transportRequests[0]['url']);

		$authReq = $session->requests[0];
		self::assertStringContainsString('aud=did%3Aweb%3Arhizopogon.us-west.host.bsky.network', $authReq['url']);
	}

	#[Test]
	public function uploadAndStatusGetDifferentTimeouts(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt-upload']);
		$session->queueResponse(200, ['token' => 'jwt-status']);

		$callIndex = 0;
		$responses = [
			['did' => 'did:plc:testuser', 'jobId' => 'j1', 'state' => 'JOB_STATE_CREATED'],
			['jobStatus' => ['jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING']],
		];
		$transport = function (string $method, string $url, string $token, ?string $body, ?string $contentType, int $timeoutSeconds) use (&$callIndex, $responses): array {
			$this->transportRequests[] = compact('method', 'url', 'token', 'body', 'contentType', 'timeoutSeconds');
			return $responses[$callIndex++];
		};

		$service = $this->buildService($session, $transport);
		$service->uploadVideo('bytes');
		$service->getJobStatus('j1');

		self::assertGreaterThan(60, $this->transportRequests[0]['timeoutSeconds']);
		self::assertLessThanOrEqual(60, $this->transportRequests[1]['timeoutSeconds']);
	}

	#[Test]
	public function uploadFilenameReflectsMimeType(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt']);

		$transport = $this->fakeTransport(['did' => 'did:plc:testuser', 'jobId' => 'j1', 'state' => 'JOB_STATE_CREATED']);
		$service = $this->buildService($session, $transport);
		$service->uploadVideo('bytes', 'video/webm');

		self::assertStringContainsString('name=video.webm', $this->transportRequests[0]['url']);
	}

	#[Test]
	public function eachCallMintsAFreshToken(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'token-1']);
		$session->queueResponse(200, ['token' => 'token-2']);

		$callIndex = 0;
		$responses = [
			['jobStatus' => ['jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING']],
			['jobStatus' => ['jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_COMPLETED',
				'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkrei'], 'mimeType' => 'video/mp4', 'size' => 1]]],
		];
		$transport = function (string $method, string $url, string $token, ?string $body, ?string $contentType, int $timeoutSeconds) use (&$callIndex, $responses): array {
			$this->transportRequests[] = compact('method', 'url', 'token', 'body', 'contentType', 'timeoutSeconds');
			return $responses[$callIndex++];
		};

		$service = $this->buildService($session, $transport);
		$service->getJobStatus('j1');
		$service->getJobStatus('j1');

		self::assertSame('token-1', $this->transportRequests[0]['token']);
		self::assertSame('token-2', $this->transportRequests[1]['token']);
	}

	#[Test]
	public function mintingThrowsWhenSessionPdsUrlHasNoHost(): void
	{
		$session = new FakeSession(pdsUrl: 'not-a-url');
		$session->queueResponse(200, ['token' => 'should-not-be-used']);

		$service = $this->buildService($session, $this->fakeTransport([]));

		$this->expectException(BlueskyException::class);
		$this->expectExceptionMessage('Cannot derive PDS DID');
		$service->getUploadLimits();
	}
}
