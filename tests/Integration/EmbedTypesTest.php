<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Integration;

use Gimucco\Bluesky\BlobRef;
use Gimucco\Bluesky\Client;
use Gimucco\Bluesky\EmbeddedExternal;
use Gimucco\Bluesky\EmbeddedImage;
use Gimucco\Bluesky\EmbeddedRecord;
use Gimucco\Bluesky\EmbeddedRecordWithMedia;
use Gimucco\Bluesky\EmbeddedVideo;
use Gimucco\Bluesky\Exception\BlueskyException;
use Gimucco\Bluesky\Exception\InvalidArgumentException;
use Gimucco\Bluesky\Tests\FakeSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmbedTypesTest extends TestCase
{
	#[Test]
	public function postWithExternalLinkCard(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/ext1',
			'cid' => 'bafyreiext',
		]);

		$thumb = new BlobRef('blob', 'image/jpeg', 50_000, 'bafkreithumb');
		$client = new Client($session);
		$client->post('Worth a read:', external: new EmbeddedExternal(
			uri: 'https://example.com/article',
			title: 'Example Article',
			description: 'A thoughtful piece on the topic.',
			thumb: $thumb,
		));

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		$embed = $req['body']['record']['embed'];
		self::assertSame('app.bsky.embed.external', $embed['$type']);
		self::assertSame('https://example.com/article', $embed['external']['uri']);
		self::assertSame('Example Article', $embed['external']['title']);
		self::assertSame('A thoughtful piece on the topic.', $embed['external']['description']);
		self::assertSame('bafkreithumb', $embed['external']['thumb']['ref']['$link']);
	}

	#[Test]
	public function postWithExternalOmitsThumbWhenNotProvided(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/ext2',
			'cid' => 'bafyreiext2',
		]);

		$client = new Client($session);
		$client->post('Just a link', external: new EmbeddedExternal(
			uri: 'https://example.com',
			title: 't',
			description: 'd',
		));

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertArrayNotHasKey('thumb', $req['body']['record']['embed']['external']);
	}

	#[Test]
	public function postQuotingAnotherPost(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/q1',
			'cid' => 'bafyreiq1',
		]);

		$client = new Client($session);
		$client->post('Look at this 👇', quoting: new EmbeddedRecord(
			uri: 'at://did:plc:alice/app.bsky.feed.post/aaa',
			cid: 'bafyreiaaa',
		));

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		$embed = $req['body']['record']['embed'];
		self::assertSame('app.bsky.embed.record', $embed['$type']);
		self::assertSame('at://did:plc:alice/app.bsky.feed.post/aaa', $embed['record']['uri']);
		self::assertSame('bafyreiaaa', $embed['record']['cid']);
	}

	#[Test]
	public function postQuotingWithImagesAttached(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/qm1',
			'cid' => 'bafyreiqm1',
		]);

		$client = new Client($session);
		$client->post('My take, with proof:', quoting: new EmbeddedRecordWithMedia(
			record: new EmbeddedRecord(uri: 'at://did:plc:alice/app.bsky.feed.post/aaa', cid: 'bafyreiaaa'),
			images: [new EmbeddedImage(new BlobRef('blob', 'image/png', 100, 'bafkreipng'), alt: 'screenshot')],
		));

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		$embed = $req['body']['record']['embed'];
		self::assertSame('app.bsky.embed.recordWithMedia', $embed['$type']);
		self::assertSame('app.bsky.embed.record', $embed['record']['$type']);
		self::assertSame('at://did:plc:alice/app.bsky.feed.post/aaa', $embed['record']['record']['uri']);
		self::assertSame('app.bsky.embed.images', $embed['media']['$type']);
		self::assertSame('screenshot', $embed['media']['images'][0]['alt']);
	}

	#[Test]
	public function postQuotingWithVideoAttached(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/qv1',
			'cid' => 'bafyreiqv',
		]);

		$client = new Client($session);
		$client->post('My take with video:', quoting: new EmbeddedRecordWithMedia(
			record: new EmbeddedRecord(uri: 'at://did:plc:alice/app.bsky.feed.post/aaa', cid: 'bafyreiaaa'),
			video: new EmbeddedVideo(new BlobRef('blob', 'video/mp4', 999, 'bafkreivid')),
		));

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		$embed = $req['body']['record']['embed'];
		self::assertSame('app.bsky.embed.recordWithMedia', $embed['$type']);
		self::assertSame('app.bsky.embed.video', $embed['media']['$type']);
		self::assertSame('bafkreivid', $embed['media']['video']['ref']['$link']);
	}

	#[Test]
	public function recordWithMediaRequiresExactlyOneOfImagesOrVideo(): void
	{
		$record = new EmbeddedRecord(uri: 'at://did:plc:alice/app.bsky.feed.post/aaa', cid: 'bafyreiaaa');

		$this->expectException(InvalidArgumentException::class);
		new EmbeddedRecordWithMedia(record: $record);  // neither images nor video
	}

	#[Test]
	public function recordWithMediaRejectsBothImagesAndVideo(): void
	{
		$record = new EmbeddedRecord(uri: 'at://did:plc:alice/app.bsky.feed.post/aaa', cid: 'bafyreiaaa');
		$blob = new BlobRef('blob', 'image/jpeg', 1, 'bafkreix');

		$this->expectException(InvalidArgumentException::class);
		new EmbeddedRecordWithMedia(
			record: $record,
			images: [$blob],
			video: new EmbeddedVideo($blob),
		);
	}

	#[Test]
	public function postRejectsMultipleEmbedTypes(): void
	{
		$session = new FakeSession();
		$client = new Client($session);
		$blob = new BlobRef('blob', 'image/jpeg', 1, 'bafkreix');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at most one embed');
		$client->post('confused', images: [$blob], external: new EmbeddedExternal(
			uri: 'https://x',
			title: 't',
			description: 'd',
		));
	}

	#[Test]
	public function awaitVideoReturnsBlobOnCompletion(): void
	{
		$session = new FakeSession();
		// Each getJobStatus call triggers a getServiceAuth on the PDS first.
		$session->queueResponse(200, ['token' => 'svc-token-1']);
		$session->queueResponse(200, ['token' => 'svc-token-2']);

		$pollResponses = [
			['jobStatus' => ['jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING', 'progress' => 50]],
			['jobStatus' => [
				'jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_COMPLETED',
				'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkreidone'], 'mimeType' => 'video/mp4', 'size' => 12345],
			]],
		];
		$callIndex = 0;
		$fakeTransport = static function () use (&$pollResponses, &$callIndex): array {
			return $pollResponses[$callIndex++];
		};

		$client = new Client($session, videoHttpTransport: $fakeTransport);
		$blob = $client->awaitVideo('j1', timeoutSeconds: 60, initialPollSeconds: 0);

		self::assertSame('bafkreidone', $blob->link);
		self::assertSame('video/mp4', $blob->mimeType);
		self::assertSame(12345, $blob->size);
		// Two getServiceAuth calls on the PDS
		self::assertCount(2, $session->requests);
	}

	#[Test]
	public function awaitVideoThrowsOnFailedState(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'svc-token']);

		$fakeTransport = static fn() => [
			'jobStatus' => [
				'jobId' => 'jbad', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_FAILED',
				'error' => 'invalid codec',
			],
		];

		$client = new Client($session, videoHttpTransport: $fakeTransport);

		$this->expectException(BlueskyException::class);
		$this->expectExceptionMessage('invalid codec');
		$client->awaitVideo('jbad', timeoutSeconds: 60, initialPollSeconds: 0);
	}

	#[Test]
	public function awaitVideoThrowsOnCompletedWithoutBlob(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['token' => 'svc-token']);

		$fakeTransport = static fn() => [
			'jobStatus' => ['jobId' => 'jodd', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_COMPLETED'],
		];

		$client = new Client($session, videoHttpTransport: $fakeTransport);

		$this->expectException(BlueskyException::class);
		$this->expectExceptionMessage('blob is missing');
		$client->awaitVideo('jodd', timeoutSeconds: 60, initialPollSeconds: 0);
	}

	#[Test]
	public function awaitVideoThrowsOnTimeout(): void
	{
		$session = new FakeSession();
		// Queue enough service-auth tokens for the polling attempts.
		for ($i = 0; $i < 5; $i++) {
			$session->queueResponse(200, ['token' => 'svc-token-'.$i]);
		}

		$fakeTransport = static fn() => [
			'jobStatus' => ['jobId' => 'jstuck', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING'],
		];

		$client = new Client($session, videoHttpTransport: $fakeTransport);

		$this->expectException(BlueskyException::class);
		$this->expectExceptionMessage('did not complete within');
		$client->awaitVideo('jstuck', timeoutSeconds: 0, initialPollSeconds: 0);
	}

	#[Test]
	public function clientUploadVideoReturnsBlobAfterAwait(): void
	{
		$session = new FakeSession();
		// uploadVideo: getServiceAuth + transport. awaitVideo poll: getServiceAuth + transport.
		$session->queueResponse(200, ['token' => 'jwt-upload']);
		$session->queueResponse(200, ['token' => 'jwt-status']);

		$callIndex = 0;
		$responses = [
			['jobStatus' => ['jobId' => 'jx', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING']],
			['jobStatus' => [
				'jobId' => 'jx', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_COMPLETED',
				'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkreivid'], 'mimeType' => 'video/mp4', 'size' => 999],
			]],
		];
		$fakeTransport = static function () use (&$callIndex, $responses): array {
			return $responses[$callIndex++];
		};

		$client = new Client($session, videoHttpTransport: $fakeTransport);
		$blob = $client->uploadVideo('FAKE_MP4_BYTES', 'video/mp4', timeoutSeconds: 30);

		self::assertSame('bafkreivid', $blob->link);
		self::assertSame('video/mp4', $blob->mimeType);
		self::assertSame(999, $blob->size);
	}

	#[Test]
	public function clientUploadVideoRejectsEmptyBytes(): void
	{
		$client = new Client(new FakeSession());
		$this->expectException(InvalidArgumentException::class);
		$client->uploadVideo('');
	}

	#[Test]
	public function clientUploadVideoRejectsEmptyMimeType(): void
	{
		$client = new Client(new FakeSession());
		$this->expectException(InvalidArgumentException::class);
		$client->uploadVideo('bytes', '');
	}

	#[Test]
	public function clientPostVideoUploadsAwaitsAndPosts(): void
	{
		$session = new FakeSession();
		// 1. getServiceAuth (upload), 2. getServiceAuth (status poll), 3. createRecord
		$session->queueResponse(200, ['token' => 'jwt-upload']);
		$session->queueResponse(200, ['token' => 'jwt-status']);
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/posted',
			'cid' => 'bafyreipostvid',
		]);

		$callIndex = 0;
		$responses = [
			// 1) upload returns a job in PROCESSING
			['jobStatus' => [
				'jobId' => 'one-shot', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING',
			]],
			// 2) await poll: COMPLETED with the resulting blob
			['jobStatus' => [
				'jobId' => 'one-shot', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_COMPLETED',
				'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkreione'], 'mimeType' => 'video/mp4', 'size' => 1234],
			]],
		];
		$fakeTransport = static function () use (&$callIndex, $responses): array {
			return $responses[$callIndex++];
		};

		$client = new Client($session, videoHttpTransport: $fakeTransport);
		$ref = $client->postVideo('Watch this', 'BYTES', alt: 'A test clip', mimeType: 'video/mp4', timeoutSeconds: 30);

		self::assertSame('at://did:plc:testuser/app.bsky.feed.post/posted', $ref->uri);

		// Verify the createRecord call carries the video embed with our blob + alt.
		$createReq = $session->requests[2];
		self::assertStringContainsString('com.atproto.repo.createRecord', $createReq['url']);
		self::assertIsArray($createReq['body']);
		$embed = $createReq['body']['record']['embed'];
		self::assertSame('app.bsky.embed.video', $embed['$type']);
		self::assertSame('bafkreione', $embed['video']['ref']['$link']);
		self::assertSame('A test clip', $embed['alt']);
	}
}
