<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Integration;

use Gimucco\Bluesky\Client;
use Gimucco\Bluesky\Tests\FakeSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaginatorTest extends TestCase
{
	#[Test]
	public function paginateTimelineWalksAllPages(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'cursor' => 'page2',
			'feed' => [
				$this->fakeFeedItem('post1'),
				$this->fakeFeedItem('post2'),
			],
		]);
		$session->queueResponse(200, [
			'cursor' => null,
			'feed' => [
				$this->fakeFeedItem('post3'),
			],
		]);

		$client = new Client($session);
		$items = iterator_to_array($client->feed->paginateTimeline(), false);

		self::assertCount(3, $items);
		self::assertSame('at://did:plc:alice/app.bsky.feed.post/post1', $items[0]->post->uri);
		self::assertSame('at://did:plc:alice/app.bsky.feed.post/post3', $items[2]->post->uri);
		self::assertCount(2, $session->requests);
		self::assertStringContainsString('cursor=page2', $session->requests[1]['url']);
	}

	#[Test]
	public function paginateTimelineRespectsMaxItems(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'cursor' => 'page2',
			'feed' => [
				$this->fakeFeedItem('post1'),
				$this->fakeFeedItem('post2'),
				$this->fakeFeedItem('post3'),
			],
		]);

		$client = new Client($session);
		$items = iterator_to_array($client->feed->paginateTimeline(maxItems: 2), false);

		self::assertCount(2, $items);
		self::assertCount(1, $session->requests);
	}

	#[Test]
	public function paginateTimelineStopsOnEmptyCursor(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'cursor' => '',
			'feed' => [$this->fakeFeedItem('only')],
		]);

		$client = new Client($session);
		$items = iterator_to_array($client->feed->paginateTimeline(), false);

		self::assertCount(1, $items);
		self::assertCount(1, $session->requests);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fakeFeedItem(string $rkey): array
	{
		return [
			'post' => [
				'uri' => 'at://did:plc:alice/app.bsky.feed.post/'.$rkey,
				'cid' => 'bafyrei'.$rkey,
				'author' => [
					'did' => 'did:plc:alice',
					'handle' => 'alice.bsky.social',
				],
				'record' => ['text' => 'Hello'],
				'indexedAt' => '2024-01-01T00:00:00Z',
			],
		];
	}
}
