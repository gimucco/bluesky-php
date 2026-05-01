<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Integration;

use ArgumentCountError;
use Gimucco\Bluesky\BlockRef;
use Gimucco\Bluesky\Client;
use Gimucco\Bluesky\Did;
use Gimucco\Bluesky\Exception\InvalidArgumentException;
use Gimucco\Bluesky\Exception\NotFoundException;
use Gimucco\Bluesky\Handle;
use Gimucco\Bluesky\Tests\FakeSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConvenienceTest extends TestCase
{
	#[Test]
	public function unrepostExtractsRkey(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, []);

		$client = new Client($session);
		$client->unrepost('at://did:plc:testuser/app.bsky.feed.repost/rp1');

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertStringContainsString('com.atproto.repo.deleteRecord', $req['url']);
		self::assertSame('app.bsky.feed.repost', $req['body']['collection']);
		self::assertSame('rp1', $req['body']['rkey']);
	}

	#[Test]
	public function blockCreatesGraphBlockRecord(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.graph.block/blk1',
			'cid' => 'bafyreiblock',
		]);

		$client = new Client($session);
		$ref = $client->block(new Did('did:plc:troll'));

		self::assertInstanceOf(BlockRef::class, $ref);
		self::assertSame('at://did:plc:testuser/app.bsky.graph.block/blk1', $ref->uri);
		self::assertSame('at://did:plc:testuser/app.bsky.graph.block/blk1', (string) $ref);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertSame('app.bsky.graph.block', $req['body']['collection']);
		self::assertSame('did:plc:troll', $req['body']['record']['subject']);
		self::assertSame('app.bsky.graph.block', $req['body']['record']['$type']);
	}

	#[Test]
	public function unblockDeletesByRkey(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, []);

		$client = new Client($session);
		$client->unblock('at://did:plc:testuser/app.bsky.graph.block/blk1');

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertSame('app.bsky.graph.block', $req['body']['collection']);
		self::assertSame('blk1', $req['body']['rkey']);
	}

	#[Test]
	public function muteCallsMuteActorProcedure(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, []);

		$client = new Client($session);
		$client->mute(new Handle('alice.bsky.social'));

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertStringContainsString('app.bsky.graph.muteActor', $req['url']);
		self::assertSame('alice.bsky.social', $req['body']['actor']);
	}

	#[Test]
	public function unmuteCallsUnmuteActorProcedure(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, []);

		$client = new Client($session);
		$client->unmute('did:plc:foo');

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertStringContainsString('app.bsky.graph.unmuteActor', $req['url']);
		self::assertSame('did:plc:foo', $req['body']['actor']);
	}

	#[Test]
	public function myProfileFetchesOwnDid(): void
	{
		$session = new FakeSession(did: 'did:plc:testuser');
		$session->queueResponse(200, [
			'did' => 'did:plc:testuser',
			'handle' => 'test.bsky.social',
			'displayName' => 'Me',
		]);

		$client = new Client($session);
		$profile = $client->myProfile();

		self::assertSame('did:plc:testuser', $profile->did);
		self::assertSame('Me', $profile->displayName);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertStringContainsString('actor=did%3Aplc%3Atestuser', $req['url']);
	}

	#[Test]
	public function getPostFetchesSingleByUri(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'posts' => [
				[
					'uri' => 'at://did:plc:alice/app.bsky.feed.post/post1',
					'cid' => 'bafyreipost1',
					'author' => ['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social'],
					'record' => ['text' => 'hello'],
					'indexedAt' => '2024-01-01T00:00:00Z',
				],
			],
		]);

		$client = new Client($session);
		$post = $client->getPost('at://did:plc:alice/app.bsky.feed.post/post1');

		self::assertSame('at://did:plc:alice/app.bsky.feed.post/post1', $post->uri);
		self::assertSame('alice.bsky.social', $post->author->handle);
	}

	#[Test]
	public function getPostThrowsNotFoundOnEmptyResponse(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['posts' => []]);

		$client = new Client($session);

		$this->expectException(NotFoundException::class);
		$client->getPost('at://did:plc:alice/app.bsky.feed.post/missing');
	}

	#[Test]
	public function threadCreatesPostThenLinkedReplies(): void
	{
		$session = new FakeSession();
		// First post
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/t1',
			'cid' => 'bafyreit1',
		]);
		// Reply 1 (parent=t1, root=t1)
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/t2',
			'cid' => 'bafyreit2',
		]);
		// Reply 2 (parent=t2, root=t1)
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/t3',
			'cid' => 'bafyreit3',
		]);

		$client = new Client($session);
		$refs = $client->thread('First', 'Second', 'Third');

		self::assertCount(3, $refs);
		self::assertSame('at://did:plc:testuser/app.bsky.feed.post/t1', $refs[0]->uri);
		self::assertSame('at://did:plc:testuser/app.bsky.feed.post/t2', $refs[1]->uri);
		self::assertSame('at://did:plc:testuser/app.bsky.feed.post/t3', $refs[2]->uri);

		// Verify reply chain in second post
		$secondReq = $session->requests[1];
		self::assertIsArray($secondReq['body']);
		self::assertSame('Second', $secondReq['body']['record']['text']);
		$reply2 = $secondReq['body']['record']['reply'];
		self::assertSame('at://did:plc:testuser/app.bsky.feed.post/t1', $reply2['parent']['uri']);
		self::assertSame('at://did:plc:testuser/app.bsky.feed.post/t1', $reply2['root']['uri']);

		// Verify third post's parent is t2 but root remains t1
		$thirdReq = $session->requests[2];
		self::assertIsArray($thirdReq['body']);
		$reply3 = $thirdReq['body']['record']['reply'];
		self::assertSame('at://did:plc:testuser/app.bsky.feed.post/t2', $reply3['parent']['uri']);
		self::assertSame('at://did:plc:testuser/app.bsky.feed.post/t1', $reply3['root']['uri']);
	}

	#[Test]
	public function threadOfOnePostJustCreatesIt(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/single',
			'cid' => 'bafyreione',
		]);

		$client = new Client($session);
		$refs = $client->thread('Just one');

		self::assertCount(1, $refs);
		self::assertCount(1, $session->requests);
		self::assertIsArray($session->requests[0]['body']);
		self::assertArrayNotHasKey('reply', $session->requests[0]['body']['record']);
	}

	#[Test]
	public function threadRejectsSingleEmptyString(): void
	{
		$session = new FakeSession();
		$client = new Client($session);

		$this->expectException(InvalidArgumentException::class);
		$client->thread('');
	}

	#[Test]
	public function threadRejectsAnyEmptyTextBeforePosting(): void
	{
		$session = new FakeSession();
		// We do NOT queue any responses — if the validation is correct,
		// no HTTP call should be attempted before the throw.
		$client = new Client($session);

		try {
			$client->thread('First', '   ', 'Third');  // middle is whitespace-only
			self::fail('Expected InvalidArgumentException for empty middle text');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString('item 1', $e->getMessage());
		}

		// Nothing should have been posted.
		self::assertSame([], $session->requests);
	}

	#[Test]
	public function threadRejectsZeroArgsAtLanguageLevel(): void
	{
		// New signature: thread(string $first, string ...$rest) — zero args
		// is now an ArgumentCountError, caught before our code even runs.
		$session = new FakeSession();
		$client = new Client($session);

		$this->expectException(ArgumentCountError::class);
		// @phpstan-ignore-next-line — intentional misuse for the type-system check
		$client->thread();
	}
}
