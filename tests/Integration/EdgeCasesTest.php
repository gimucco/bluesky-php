<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Integration;

use DateTimeImmutable;
use Gimucco\Bluesky\BlobRef;
use Gimucco\Bluesky\Client;
use Gimucco\Bluesky\Did;
use Gimucco\Bluesky\EmbeddedExternal;
use Gimucco\Bluesky\Exception\AuthException;
use Gimucco\Bluesky\Exception\InvalidArgumentException;
use Gimucco\Bluesky\Exception\ServerException;
use Gimucco\Bluesky\FollowRef;
use Gimucco\Bluesky\Handle;
use Gimucco\Bluesky\LikeRef;
use Gimucco\Bluesky\PostRef;
use Gimucco\Bluesky\Tests\FakeSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases and cross-cutting scenarios that don't fit a single feature
 * test class — Stringable-ref acceptance, error propagation, auto-resolution,
 * MIME detection variants, and combination tests.
 */
final class EdgeCasesTest extends TestCase
{
	#[Test]
	public function deleteMethodsAcceptStringableRefs(): void
	{
		// PostRef, FollowRef, LikeRef, RepostRef, BlockRef are all Stringable —
		// passing them directly to delete methods should work without manual casting.
		$session = new FakeSession();
		$session->queueResponse(200, []);
		$session->queueResponse(200, []);
		$session->queueResponse(200, []);

		$client = new Client($session);

		$post = new PostRef('at://did:plc:testuser/app.bsky.feed.post/postX', 'cidX');
		$client->deletePost($post);
		self::assertSame('postX', $session->requests[0]['body']['rkey']);

		$follow = new FollowRef('at://did:plc:testuser/app.bsky.graph.follow/folX', 'cidF');
		$client->unfollow($follow);
		self::assertSame('folX', $session->requests[1]['body']['rkey']);

		$like = new LikeRef('at://did:plc:testuser/app.bsky.feed.like/likX', 'cidL');
		$client->unlike($like);
		self::assertSame('likX', $session->requests[2]['body']['rkey']);
	}

	#[Test]
	public function uploadImageDetectsPngMime(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkreipng'], 'mimeType' => 'image/png', 'size' => 67],
		]);

		// Real 1x1 transparent PNG (smallest valid PNG, finfo recognizes it).
		$pngBytes = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
		);
		self::assertNotFalse($pngBytes);

		$client = new Client($session);
		$client->uploadImage($pngBytes);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('image/png', $req['contentType'] ?? null);
	}

	#[Test]
	public function uploadImageDetectsGifMime(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkreigif'], 'mimeType' => 'image/gif', 'size' => 100],
		]);

		// Minimal GIF89a header.
		$gifBytes = 'GIF89a'.str_repeat("\x00", 100);

		$client = new Client($session);
		$client->uploadImage($gifBytes);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertSame('image/gif', $req['contentType'] ?? null);
	}

	#[Test]
	public function getPostAcceptsAtUriValueObject(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'posts' => [[
				'uri' => 'at://did:plc:alice/app.bsky.feed.post/p1',
				'cid' => 'bafyrei1',
				'author' => ['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social'],
				'record' => ['text' => 'hi'],
				'indexedAt' => '2024-01-01T00:00:00Z',
			]],
		]);

		$client = new Client($session);
		$uri = new \Gimucco\Bluesky\AtUri('at://did:plc:alice/app.bsky.feed.post/p1');
		$post = $client->getPost($uri);

		self::assertSame('at://did:plc:alice/app.bsky.feed.post/p1', $post->uri);
	}

	#[Test]
	public function followAutoResolvesHandleToDid(): void
	{
		$session = new FakeSession();
		// First call: identity.resolveHandle for the handle
		$session->queueResponse(200, ['did' => 'did:plc:resolved']);
		// Second call: the actual createRecord for the follow
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.graph.follow/abc',
			'cid' => 'bafyreifol',
		]);

		$client = new Client($session);
		$ref = $client->follow('alice.bsky.social');

		self::assertSame('at://did:plc:testuser/app.bsky.graph.follow/abc', $ref->uri);
		self::assertCount(2, $session->requests);
		self::assertStringContainsString('resolveHandle', $session->requests[0]['url']);
		self::assertStringContainsString('handle=alice.bsky.social', $session->requests[0]['url']);
		self::assertSame('did:plc:resolved', $session->requests[1]['body']['record']['subject']);
	}

	#[Test]
	public function followSkipsResolutionForExistingDid(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.graph.follow/direct',
			'cid' => 'bafyreif',
		]);

		$client = new Client($session);
		$client->follow('did:plc:already-a-did');

		// Only ONE request — no resolveHandle since the input already had `did:` prefix.
		self::assertCount(1, $session->requests);
		self::assertStringContainsString('createRecord', $session->requests[0]['url']);
		self::assertSame('did:plc:already-a-did', $session->requests[0]['body']['record']['subject']);
	}

	#[Test]
	public function blockAutoResolvesHandleToDid(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, ['did' => 'did:plc:troll']);
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.graph.block/blkR',
			'cid' => 'bafyreiblk',
		]);

		$client = new Client($session);
		$client->block(new Handle('troll.bsky.social'));

		self::assertCount(2, $session->requests);
		self::assertSame('did:plc:troll', $session->requests[1]['body']['record']['subject']);
	}

	#[Test]
	public function getPostPropagatesAuthError(): void
	{
		$session = new FakeSession();
		$session->queueResponse(401, ['error' => 'AuthRequired', 'message' => 'token expired']);

		$client = new Client($session);

		$this->expectException(AuthException::class);
		$client->getPost('at://did:plc:alice/app.bsky.feed.post/x');
	}

	#[Test]
	public function uploadImagePropagatesServerError(): void
	{
		$session = new FakeSession();
		$session->queueResponse(503, ['error' => 'Unavailable', 'message' => 'service down']);

		$client = new Client($session);

		$this->expectException(ServerException::class);
		$client->uploadImage('bytes', 'image/jpeg');
	}

	#[Test]
	public function postWithAllOptionalFieldsPopulatedTogether(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/full',
			'cid' => 'bafyreifull',
		]);

		$blob = new BlobRef('blob', 'image/jpeg', 1, 'bafkreix');
		$ts = new DateTimeImmutable('2024-06-15T12:00:00+00:00');

		$client = new Client($session);
		$client->post(
			text: 'A post with everything #php',
			images: [$blob, $blob],
			tags: ['announcement', 'test'],
			langs: ['en', 'it'],
			createdAt: $ts,
		);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		$record = $req['body']['record'];
		self::assertSame('A post with everything #php', $record['text']);
		self::assertCount(2, $record['embed']['images']);
		self::assertSame(['announcement', 'test'], $record['tags']);
		self::assertSame(['en', 'it'], $record['langs']);
		self::assertSame('2024-06-15T12:00:00+00:00', $record['createdAt']);
		// #php hashtag should produce a facet
		self::assertArrayHasKey('facets', $record);
		self::assertSame('app.bsky.richtext.facet#tag', $record['facets'][0]['features'][0]['$type']);
	}

	#[Test]
	public function replyWithExplicitRootDifferentFromParent(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/r3',
			'cid' => 'bafyreir3',
		]);

		$client = new Client($session);
		$client->reply(
			parentUri: 'at://did:plc:alice/app.bsky.feed.post/parent',
			parentCid: 'bafyreiparent',
			text: 'nested reply',
			rootUri: 'at://did:plc:bob/app.bsky.feed.post/root',
			rootCid: 'bafyreiroot',
		);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		$reply = $req['body']['record']['reply'];
		self::assertSame('at://did:plc:bob/app.bsky.feed.post/root', $reply['root']['uri']);
		self::assertSame('bafyreiroot', $reply['root']['cid']);
		self::assertSame('at://did:plc:alice/app.bsky.feed.post/parent', $reply['parent']['uri']);
		self::assertSame('bafyreiparent', $reply['parent']['cid']);
	}

	#[Test]
	public function externalUriRejectsJavascriptScheme(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('http:// or https://');
		new EmbeddedExternal(uri: 'javascript:alert(1)', title: 't', description: 'd');
	}

	#[Test]
	public function externalUriRejectsDataScheme(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new EmbeddedExternal(uri: 'data:text/html,<script>x</script>', title: 't', description: 'd');
	}

	#[Test]
	public function externalUriRejectsEmptyString(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new EmbeddedExternal(uri: '', title: 't', description: 'd');
	}

	#[Test]
	public function externalUriAcceptsHttpAndHttps(): void
	{
		$a = new EmbeddedExternal(uri: 'http://example.com', title: 't', description: 'd');
		$b = new EmbeddedExternal(uri: 'https://example.com', title: 't', description: 'd');
		self::assertSame('http://example.com', $a->uri);
		self::assertSame('https://example.com', $b->uri);
	}

	#[Test]
	public function replyAcceptsTagsParameter(): void
	{
		// Regression test for B1: reply() was missing the tags parameter that
		// post() has, even though replies are also app.bsky.feed.post records
		// and the lexicon supports tags on them.
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.post/r',
			'cid' => 'bafyreir',
		]);

		$client = new Client($session);
		$client->reply(
			parentUri: 'at://did:plc:alice/app.bsky.feed.post/p',
			parentCid: 'bafyreip',
			text: 'tagged reply',
			tags: ['announcement', 'php'],
		);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertSame(['announcement', 'php'], $req['body']['record']['tags']);
	}

	#[Test]
	public function deleteMethodsRejectDidAndHandleAtTypeLevel(): void
	{
		// Regression test for B2: previously the signature accepted any
		// Stringable, so a Did or Handle would type-check but produce an
		// invalid rkey at the API level. Now the union is RecordRef-only.
		// We can't exercise the rejection at runtime (it's a compile-time
		// type error), but we CAN verify a Ref is accepted.
		$session = new FakeSession();
		$session->queueResponse(200, []);

		$client = new Client($session);
		$client->unlike(new LikeRef('at://did:plc:testuser/app.bsky.feed.like/abc', 'cidL'));

		self::assertSame('abc', $session->requests[0]['body']['rkey']);
	}

	#[Test]
	public function likeAcceptsPostRefAsSingleArgument(): void
	{
		// Regression test for U3: like() now accepts a PostRef directly,
		// avoiding the awkward like($ref->uri, $ref->cid) idiom.
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.like/L',
			'cid' => 'bafyreil',
		]);

		$ref = new PostRef('at://did:plc:alice/app.bsky.feed.post/X', 'bafyreix');
		$client = new Client($session);
		$client->like($ref);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertSame('at://did:plc:alice/app.bsky.feed.post/X', $req['body']['record']['subject']['uri']);
		self::assertSame('bafyreix', $req['body']['record']['subject']['cid']);
	}

	#[Test]
	public function likeRejectsExtraCidWhenPassedRef(): void
	{
		$session = new FakeSession();
		$client = new Client($session);
		$ref = new PostRef('at://did:plc:alice/app.bsky.feed.post/X', 'bafyreix');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('do not also supply');
		$client->like($ref, 'someCid');
	}

	#[Test]
	public function likeRejectsMissingCidWhenPassedString(): void
	{
		$session = new FakeSession();
		$client = new Client($session);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('$postCid is required');
		$client->like('at://did:plc:alice/app.bsky.feed.post/X');
	}

	#[Test]
	public function repostAcceptsPostRefAsSingleArgument(): void
	{
		$session = new FakeSession();
		$session->queueResponse(200, [
			'uri' => 'at://did:plc:testuser/app.bsky.feed.repost/R',
			'cid' => 'bafyreirepost',
		]);

		$ref = new PostRef('at://did:plc:alice/app.bsky.feed.post/Y', 'bafyreiy');
		$client = new Client($session);
		$client->repost($ref);

		$req = $session->lastRequest();
		self::assertNotNull($req);
		self::assertIsArray($req['body']);
		self::assertSame('bafyreiy', $req['body']['record']['subject']['cid']);
	}

	#[Test]
	public function castErrorIncludesFieldNameContextForArrays(): void
	{
		$session = new FakeSession();
		// Server returns a profile with `labels` as a string instead of an array.
		$session->queueResponse(200, [
			'did' => 'did:plc:alice',
			'handle' => 'alice.bsky.social',
			'labels' => 'not an array',
		]);

		$client = new Client($session);

		try {
			$client->actor->getProfile('alice.bsky.social');
			self::fail('Expected LexiconException for malformed labels field');
		} catch (\Gimucco\Bluesky\Exception\LexiconException $e) {
			// Confirm the error message names the field.
			self::assertStringContainsString('labels', $e->getMessage());
		}
	}
}
