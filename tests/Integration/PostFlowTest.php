<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Integration;

use Gimucco\Bluesky\BlobRef;
use Gimucco\Bluesky\Client;
use Gimucco\Bluesky\PostRef;
use Gimucco\Bluesky\Tests\FakeSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostFlowTest extends TestCase
{
	#[Test]
	public function createSimplePost(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/abc123',
			'cid' => 'bafyreiabc123',
		]);

		$client = new Client($session);
		$result = $client->post('Hello world');

		self::assertInstanceOf(PostRef::class, $result);
		self::assertSame('at://did:plc:testuser/app.bsky.feed.post/abc123', $result->uri);
		self::assertSame('bafyreiabc123', $result->cid);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('POST', $req['method']);
		self::assertStringContainsString('com.atproto.repo.createRecord', $req['url']);
		self::assertSame('did:plc:testuser', $req['body']['repo']);
		self::assertSame('app.bsky.feed.post', $req['body']['collection']);
		self::assertSame('Hello world', $req['body']['record']['text']);
		self::assertSame('app.bsky.feed.post', $req['body']['record']['$type']);
		self::assertArrayHasKey('createdAt', $req['body']['record']);
	}

	#[Test]
	public function deletePost(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, []);

		$client = new Client($session);
		$client->deletePost('at://did:plc:testuser/app.bsky.feed.post/abc123');

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('POST', $req['method']);
		self::assertStringContainsString('com.atproto.repo.deleteRecord', $req['url']);
		self::assertSame('did:plc:testuser', $req['body']['repo']);
		self::assertSame('app.bsky.feed.post', $req['body']['collection']);
		self::assertSame('abc123', $req['body']['rkey']);
	}

	#[Test]
	public function deletePostWithRkey(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, []);

		$client = new Client($session);
		$client->deletePost('mykey123');

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('mykey123', $req['body']['rkey']);
	}

	#[Test]
	public function postWithImages(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/img123',
			'cid' => 'bafyreiimg123',
		]);

		$blobRef = new BlobRef('blob', 'image/jpeg', 1000, 'bafkreiaaaa');

		$client = new Client($session);
		$result = $client->post('Post with image', images: [$blobRef]);

		self::assertSame('at://did:plc:testuser/app.bsky.feed.post/img123', $result->uri);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('Post with image', $req['body']['record']['text']);
		self::assertSame('app.bsky.embed.images', $req['body']['record']['embed']['$type']);
		self::assertCount(1, $req['body']['record']['embed']['images']);
	}
}
