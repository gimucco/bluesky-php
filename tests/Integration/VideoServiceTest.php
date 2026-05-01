<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Integration;

use Closure;
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
	public function uploadVideoMintsServiceAuthAndRoutesToVideoService(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt-upload']);

		$service = $this->buildService($session, $this->fakeTransport([
			'jobStatus' => ['jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING'],
		]));
		$result = $service->uploadVideo('VIDEO_BYTES');

		self::assertSame('j1', $result->jobStatus->jobId);

		// getServiceAuth was called on the PDS
		$authReq = $session->requests[0];
		self::assertStringContainsString('com.atproto.server.getServiceAuth', $authReq['url']);
		self::assertStringContainsString('did%3Aweb%3Avideo.bsky.app', $authReq['url']);
		self::assertStringContainsString('lxm=app.bsky.video.uploadVideo', $authReq['url']);

		// Upload was sent to video.bsky.app
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
		self::assertStringContainsString('lxm=app.bsky.video.getUploadLimits', $authReq['url']);

		self::assertCount(1, $this->transportRequests);
		$req = $this->transportRequests[0];
		self::assertSame('GET', $req['method']);
		self::assertSame('https://video.bsky.app/xrpc/app.bsky.video.getUploadLimits', $req['url']);
		self::assertSame('jwt-limits', $req['token']);
	}

	#[Test]
	public function customVideoServiceUrlIsUsedAndDerivesServiceDid(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt-custom']);

		$transport = $this->fakeTransport(['canUpload' => true]);
		$service = new VideoService($session, new Server($session), 'https://custom-video.example.com', $transport);
		$service->getUploadLimits();

		// URL routes to the custom host
		self::assertSame('https://custom-video.example.com/xrpc/app.bsky.video.getUploadLimits', $this->transportRequests[0]['url']);

		// Service DID is auto-derived from the URL host so the service-auth
		// token's `aud` matches the custom service identity.
		$authReq = $session->requests[0];
		self::assertStringContainsString('aud=did%3Aweb%3Acustom-video.example.com', $authReq['url']);
	}

	#[Test]
	public function constructorRejectsUrlWithoutHost(): void
	{
		$this->expectException(\Gimucco\Bluesky\Exception\InvalidArgumentException::class);
		$this->expectExceptionMessage('videoServiceUrl must include a host');
		new VideoService(new FakeSession(), new Server(new FakeSession()), 'not-a-url');
	}

	#[Test]
	public function uploadAndStatusGetDifferentTimeouts(): void
	{
		$session = new FakeSession();
		// Two pairs: upload (auth + transport), status (auth + transport)
		$session->queueResponse(200, ['token' => 'jwt-upload']);
		$session->queueResponse(200, ['token' => 'jwt-status']);

		$callIndex = 0;
		$responses = [
			['jobStatus' => ['jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING']],
			['jobStatus' => ['jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING']],
		];
		$transport = function (string $method, string $url, string $token, ?string $body, ?string $contentType, int $timeoutSeconds) use (&$callIndex, $responses): array {
			$this->transportRequests[] = compact('method', 'url', 'token', 'body', 'contentType', 'timeoutSeconds');
			return $responses[$callIndex++];
		};

		$service = $this->buildService($session, $transport);
		$service->uploadVideo('bytes');
		$service->getJobStatus('j1');

		// Upload gets a generous timeout for bytes-on-the-wire; status is short.
		self::assertGreaterThan(60, $this->transportRequests[0]['timeoutSeconds']);
		self::assertLessThanOrEqual(60, $this->transportRequests[1]['timeoutSeconds']);
	}

	#[Test]
	public function uploadFilenameReflectsMimeType(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'jwt']);

		$transport = $this->fakeTransport([
			'jobStatus' => ['jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING'],
		]);
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
}
