<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Integration;

use Gimucco\Bluesky\BlobRef;
use Gimucco\Bluesky\Client;
use Gimucco\Bluesky\EmbeddedImage;
use Gimucco\Bluesky\EmbeddedVideo;
use Gimucco\Bluesky\Exception\InvalidArgumentException;
use Gimucco\Bluesky\Tests\FakeSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlobUploadTest extends TestCase
{
	#[Test]
	public function uploadImageSendsRawBodyAndExplicitContentType(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'blob' => [
				'$type' => 'blob',
				'ref' => ['$link' => 'bafkreiabc123'],
				'mimeType' => 'image/jpeg',
				'size' => 12345,
			],
		]);

		// 1×1 pixel JPEG header — enough that finfo detects "image/jpeg".
		$jpegBytes = base64_decode(
			'/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AL+f/9k=',
			true,
		);
		self::assertNotFalse($jpegBytes);

		$client = new Client($session);
		$blob = $client->uploadImage($jpegBytes);

		self::assertInstanceOf(BlobRef::class, $blob);
		self::assertSame('bafkreiabc123', $blob->link);
		self::assertSame('image/jpeg', $blob->mimeType);
		self::assertSame(12345, $blob->size);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('POST', $req['method']);
		self::assertStringContainsString('com.atproto.repo.uploadBlob', $req['url']);
		self::assertSame($jpegBytes, $req['body']);
		// finfo should detect this as image/jpeg
		self::assertSame('image/jpeg', $req['contentType'] ?? null);
	}

	#[Test]
	public function uploadImageRespectsExplicitMimeType(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'blob' => [
				'$type' => 'blob',
				'ref' => ['$link' => 'bafkrei0001'],
				'mimeType' => 'image/png',
				'size' => 4,
			],
		]);

		$client = new Client($session);
		$client->uploadImage("\x89PNG", 'image/png');

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('image/png', $req['contentType'] ?? null);
		self::assertSame("\x89PNG", $req['body']);
	}

	#[Test]
	public function uploadImageRejectsEmptyBytes(): void
	{
		$session = new FakeSession();
		$client = new Client($session);

		$this->expectException(InvalidArgumentException::class);
		$client->uploadImage('');
	}

	#[Test]
	public function uploadImageRejectsEmptyMimeType(): void
	{
		$session = new FakeSession();
		$client = new Client($session);

		$this->expectException(InvalidArgumentException::class);
		$client->uploadImage('some bytes', '');
	}

	#[Test]
	public function postWithEmbeddedImageIncludesAltText(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/imgalt',
			'cid' => 'bafyreialt',
		]);

		$blob = new BlobRef('blob', 'image/jpeg', 1000, 'bafkreialt');
		$image = new EmbeddedImage($blob, alt: 'A friendly cat sitting on a windowsill');

		$client = new Client($session);
		$client->post('Look at this cat', images: [$image]);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertSame('app.bsky.embed.images', $req['body']['record']['embed']['$type']);
		self::assertCount(1, $req['body']['record']['embed']['images']);
		self::assertSame('A friendly cat sitting on a windowsill', $req['body']['record']['embed']['images'][0]['alt']);
	}

	#[Test]
	public function postWithBareBlobRefDefaultsAltToEmpty(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/bare',
			'cid' => 'bafyreibare',
		]);

		$blob = new BlobRef('blob', 'image/jpeg', 1000, 'bafkreibare');

		$client = new Client($session);
		$client->post('No alt', images: [$blob]);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertSame('', $req['body']['record']['embed']['images'][0]['alt']);
	}

	#[Test]
	public function postWithVideoEmbed(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/vid',
			'cid' => 'bafyreivpost',
		]);

		$videoBlob = new BlobRef('blob', 'video/mp4', 5_000_000, 'bafkreivid');

		$client = new Client($session);
		$client->post('Watch this', video: new EmbeddedVideo($videoBlob, alt: 'A friendly waving cat'));

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		$embed = $req['body']['record']['embed'];
		self::assertSame('app.bsky.embed.video', $embed['$type']);
		self::assertSame('bafkreivid', $embed['video']['ref']['$link']);
		self::assertSame('video/mp4', $embed['video']['mimeType']);
		self::assertSame('A friendly waving cat', $embed['alt']);
	}

	#[Test]
	public function postWithVideoEmbedOmitsAltWhenEmpty(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/vidn',
			'cid' => 'bafyreivnoa',
		]);

		$client = new Client($session);
		$client->post('No alt video', video: new EmbeddedVideo(new BlobRef('blob', 'video/mp4', 1, 'bafkreiv2')));

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertArrayNotHasKey('alt', $req['body']['record']['embed']);
	}

	#[Test]
	public function postRejectsBothImagesAndVideo(): void
	{
		$session = new FakeSession();
		$client = new Client($session);
		$blob = new BlobRef('blob', 'image/jpeg', 100, 'bafkreix');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at most one embed');
		$client->post('Both', images: [$blob], video: new EmbeddedVideo($blob));
	}

	#[Test]
	public function postRejectsMoreThanFourImages(): void
	{
		$session = new FakeSession();
		$client = new Client($session);
		$blob = new BlobRef('blob', 'image/jpeg', 100, 'bafkreix');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at most 4 images (got 5)');
		$client->post('Too many', images: [$blob, $blob, $blob, $blob, $blob]);
	}

	#[Test]
	public function postAcceptsExactlyFourImages(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/4i',
			'cid' => 'bafyrei4i',
		]);
		$client = new Client($session);
		$blob = new BlobRef('blob', 'image/jpeg', 100, 'bafkreix');

		$client->post('Four images', images: [$blob, $blob, $blob, $blob]);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertCount(4, $req['body']['record']['embed']['images']);
	}

	#[Test]
	public function uploadVideoRoutesToVideoServiceWithServiceAuth(): void
	{
		$session = new FakeSession();
		// Queue getServiceAuth response (called on PDS via Session)
		$session->queueResponse(200, ['token' => 'service-jwt-for-upload']);

		$transportRequests = [];
		$fakeTransport = static function (string $method, string $url, string $token, ?string $body, ?string $contentType, int $timeoutSeconds) use (&$transportRequests): array {
			$transportRequests[] = compact('method', 'url', 'token', 'body', 'contentType', 'timeoutSeconds');
			return [
				'jobStatus' => [
					'jobId' => 'abc123',
					'did' => 'did:plc:testuser',
					'state' => 'JOB_STATE_PROCESSING',
				],
			];
		};

		$client = new Client($session, videoHttpTransport: $fakeTransport);
		$result = $client->video->uploadVideo('FAKE_VIDEO_BYTES');

		self::assertSame('abc123', $result->jobStatus->jobId);

		// Verify getServiceAuth was called on PDS
		$authReq = $session->requests[0];
		self::assertStringContainsString('com.atproto.server.getServiceAuth', $authReq['url']);
		self::assertStringContainsString('did%3Aweb%3Avideo.bsky.app', $authReq['url']);
		self::assertStringContainsString('app.bsky.video.uploadVideo', $authReq['url']);

		// Verify video upload was sent to video.bsky.app with Bearer token
		self::assertCount(1, $transportRequests);
		$vidReq = $transportRequests[0];
		self::assertSame('POST', $vidReq['method']);
		self::assertStringStartsWith('https://video.bsky.app/xrpc/app.bsky.video.uploadVideo', $vidReq['url']);
		self::assertStringContainsString('did=did%3Aplc%3Atestuser', $vidReq['url']);
		self::assertSame('service-jwt-for-upload', $vidReq['token']);
		self::assertSame('FAKE_VIDEO_BYTES', $vidReq['body']);
		self::assertSame('video/mp4', $vidReq['contentType']);
	}

	#[Test]
	public function uploadImagePropagatesApiErrors(): void
	{
		$session = new FakeSession();
		$session->queueResponse(413, [
			'error' => 'PayloadTooLarge',
			'message' => 'blob exceeds maximum size',
		]);

		$client = new Client($session);

		$this->expectException(\Gimucco\Bluesky\Exception\ApiException::class);
		$client->uploadImage('any bytes', 'image/jpeg');
	}

	#[Test]
	public function generatedUploadBlobUsesRawRequest(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'blob' => [
				'$type' => 'blob',
				'ref' => ['$link' => 'bafkreidirect'],
				'mimeType' => 'video/mp4',
				'size' => 999,
			],
		]);

		$client = new Client($session);
		$result = $client->repo->uploadBlob('VIDEO_BYTES', 'video/mp4');

		// The generated method returns UploadBlobOutput with the raw blob array.
		self::assertIsArray($result->blob);
		self::assertSame('bafkreidirect', $result->blob['ref']['$link']);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('POST', $req['method']);
		self::assertSame('VIDEO_BYTES', $req['body']);
		self::assertSame('video/mp4', $req['contentType'] ?? null);
	}
}
