<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Integration;

use Gimucco\Bluesky\Client;
use Gimucco\Bluesky\Tests\FakeSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FeedReadTest extends TestCase
{
	#[Test]
	public function getProfile(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'did' => 'did:plc:alice',
			'handle' => 'alice.bsky.social',
			'displayName' => 'Alice',
			'description' => 'Hello world',
			'followersCount' => 100,
			'followsCount' => 50,
			'postsCount' => 42,
		]);

		$client = new Client($session);
		$profile = $client->actor->getProfile('alice.bsky.social');

		self::assertSame('did:plc:alice', $profile->did);
		self::assertSame('alice.bsky.social', $profile->handle);
		self::assertSame('Alice', $profile->displayName);
		self::assertSame(100, $profile->followersCount);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('GET', $req['method']);
		self::assertStringContainsString('app.bsky.actor.getProfile', $req['url']);
		self::assertStringContainsString('actor=alice.bsky.social', $req['url']);
	}

	#[Test]
	public function getTimeline(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'cursor' => 'next-page',
			'feed' => [
				[
					'post' => [
						'uri' => 'at://did:plc:alice/app.bsky.feed.post/post1',
						'cid' => 'bafyreipost1',
						'author' => [
							'did' => 'did:plc:alice',
							'handle' => 'alice.bsky.social',
						],
						'record' => ['text' => 'Hello'],
						'indexedAt' => '2024-01-01T00:00:00Z',
					],
				],
			],
		]);

		$client = new Client($session);
		$timeline = $client->feed->getTimeline();

		self::assertSame('next-page', $timeline->cursor);
		self::assertCount(1, $timeline->feed);
		self::assertSame('at://did:plc:alice/app.bsky.feed.post/post1', $timeline->feed[0]->post->uri);
		self::assertSame('alice.bsky.social', $timeline->feed[0]->post->author->handle);
	}

	#[Test]
	public function apiExceptionOnError(): void
	{
		$session = new FakeSession();
		$session->queueResponse(404, [
			'error' => 'NotFound',
			'message' => 'Profile not found',
		]);

		$client = new Client($session);

		$this->expectException(\Gimucco\Bluesky\Exception\NotFoundException::class);
		$client->actor->getProfile('nonexistent.bsky.social');
	}
}
