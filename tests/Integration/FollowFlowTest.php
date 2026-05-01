<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Integration;

use Gimucco\Bluesky\Client;
use Gimucco\Bluesky\FollowRef;
use Gimucco\Bluesky\Tests\FakeSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FollowFlowTest extends TestCase
{
	#[Test]
	public function follow(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.graph.follow/follow123',
			'cid' => 'bafyreifollow',
		]);

		$client = new Client($session);
		$result = $client->follow('did:plc:target');

		self::assertInstanceOf(FollowRef::class, $result);
		self::assertSame('at://did:plc:testuser/app.bsky.graph.follow/follow123', $result->uri);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('POST', $req['method']);
		self::assertStringContainsString('com.atproto.repo.createRecord', $req['url']);
		self::assertSame('did:plc:testuser', $req['body']['repo']);
		self::assertSame('app.bsky.graph.follow', $req['body']['collection']);
		self::assertSame('did:plc:target', $req['body']['record']['subject']);
		self::assertSame('app.bsky.graph.follow', $req['body']['record']['$type']);
	}

	#[Test]
	public function unfollow(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, []);

		$client = new Client($session);
		$client->unfollow('at://did:plc:testuser/app.bsky.graph.follow/follow123');

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('POST', $req['method']);
		self::assertStringContainsString('com.atproto.repo.deleteRecord', $req['url']);
		self::assertSame('app.bsky.graph.follow', $req['body']['collection']);
		self::assertSame('follow123', $req['body']['rkey']);
	}

	#[Test]
	public function like(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.like/like123',
			'cid' => 'bafyreilike',
		]);

		$client = new Client($session);
		$result = $client->like('at://did:plc:other/app.bsky.feed.post/post1', 'bafyreipost1');

		self::assertSame('at://did:plc:testuser/app.bsky.feed.like/like123', $result->uri);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('app.bsky.feed.like', $req['body']['record']['$type']);
		self::assertSame('at://did:plc:other/app.bsky.feed.post/post1', $req['body']['record']['subject']['uri']);
		self::assertSame('bafyreipost1', $req['body']['record']['subject']['cid']);
	}

	#[Test]
	public function repost(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.repost/repost123',
			'cid' => 'bafyreirepost',
		]);

		$client = new Client($session);
		$result = $client->repost('at://did:plc:other/app.bsky.feed.post/post1', 'bafyreipost1');

		self::assertSame('at://did:plc:testuser/app.bsky.feed.repost/repost123', $result->uri);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('app.bsky.feed.repost', $req['body']['record']['$type']);
	}
}
