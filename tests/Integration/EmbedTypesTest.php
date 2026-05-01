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
		// Two polls: first PROCESSING, second COMPLETED.
		$session->queueResponse(200, [
			'jobStatus' => ['jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING', 'progress' => 50],
		]);
		$session->queueResponse(200, [
			'jobStatus' => [
				'jobId' => 'j1', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_COMPLETED',
				'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkreidone'], 'mimeType' => 'video/mp4', 'size' => 12345],
			],
		]);

		$client = new Client($session);
		// Use 0-second initial poll to keep test fast.
		$blob = $client->awaitVideo('j1', timeoutSeconds: 60, initialPollSeconds: 0);

		self::assertSame('bafkreidone', $blob->link);
		self::assertSame('video/mp4', $blob->mimeType);
		self::assertSame(12345, $blob->size);
		self::assertCount(2, $session->requests);
	}

	#[Test]
	public function awaitVideoThrowsOnFailedState(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'jobStatus' => [
				'jobId' => 'jbad', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_FAILED',
				'error' => 'invalid codec',
			],
		]);

		$client = new Client($session);

		$this->expectException(BlueskyException::class);
		$this->expectExceptionMessage('invalid codec');
		$client->awaitVideo('jbad', timeoutSeconds: 60, initialPollSeconds: 0);
	}

	#[Test]
	public function awaitVideoThrowsOnCompletedWithoutBlob(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'jobStatus' => ['jobId' => 'jodd', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_COMPLETED'],
		]);

		$client = new Client($session);

		$this->expectException(BlueskyException::class);
		$this->expectExceptionMessage('blob is missing');
		$client->awaitVideo('jodd', timeoutSeconds: 60, initialPollSeconds: 0);
	}

	#[Test]
	public function awaitVideoThrowsOnTimeout(): void
	{
		$session = new FakeSession();
		// Always respond with PROCESSING — never completes.
		for ($i = 0; $i < 5; $i++) {
			$session->queueResponse(200, [
				'jobStatus' => ['jobId' => 'jstuck', 'did' => 'did:plc:testuser', 'state' => 'JOB_STATE_PROCESSING'],
			]);
		}

		$client = new Client($session);

		$this->expectException(BlueskyException::class);
		$this->expectExceptionMessage('did not complete within');
		// Zero timeout + zero poll → first iteration runs, then deadline check fires.
		$client->awaitVideo('jstuck', timeoutSeconds: 0, initialPollSeconds: 0);
	}
}
