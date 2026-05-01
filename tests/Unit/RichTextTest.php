<?php

declare(strict_types=1);

namespace Gimucco\Bluesky\Tests\Unit;

use Gimucco\Bluesky\RichText;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RichTextTest extends TestCase
{
	#[Test]
	public function plainTextRoundTrips(): void
	{
		$rt = new RichText('Hello world');
		self::assertSame('Hello world', $rt->plainText());
	}

	#[Test]
	public function detectsLinks(): void
	{
		$rt = new RichText('Check out https://bsky.app and http://example.com for more');
		$facets = $rt->facets(self::neverResolveHandle());

		self::assertCount(2, $facets);
		self::assertSame('app.bsky.richtext.facet#link', $facets[0]['features'][0]['$type']);
		self::assertSame('https://bsky.app', $facets[0]['features'][0]['uri']);
		self::assertSame('app.bsky.richtext.facet#link', $facets[1]['features'][0]['$type']);
		self::assertSame('http://example.com', $facets[1]['features'][0]['uri']);
	}

	#[Test]
	public function linkByteOffsetsAreCorrect(): void
	{
		$rt = new RichText('Visit https://example.com now');
		$facets = $rt->facets(self::neverResolveHandle());

		self::assertCount(1, $facets);
		self::assertSame(6, $facets[0]['index']['byteStart']);
		self::assertSame(25, $facets[0]['index']['byteEnd']);
	}

	#[Test]
	public function detectsHashtags(): void
	{
		$rt = new RichText('Hello #world #php');
		$facets = $rt->facets(self::neverResolveHandle());

		self::assertCount(2, $facets);
		self::assertSame('app.bsky.richtext.facet#tag', $facets[0]['features'][0]['$type']);
		self::assertSame('world', $facets[0]['features'][0]['tag']);
		self::assertSame('app.bsky.richtext.facet#tag', $facets[1]['features'][0]['$type']);
		self::assertSame('php', $facets[1]['features'][0]['tag']);
	}

	#[Test]
	public function hashtagByteOffsetsAreCorrect(): void
	{
		$rt = new RichText('Hello #world');
		$facets = $rt->facets(self::neverResolveHandle());

		self::assertCount(1, $facets);
		self::assertSame(6, $facets[0]['index']['byteStart']);
		self::assertSame(12, $facets[0]['index']['byteEnd']);
	}

	#[Test]
	public function hashtagDoesNotMatchMidWord(): void
	{
		$rt = new RichText('test#notahashtag');
		$facets = $rt->facets(self::neverResolveHandle());

		self::assertCount(0, $facets);
	}

	#[Test]
	public function unicodeByteOffsetsAreCorrect(): void
	{
		$rt = new RichText("\u{1F600} #hello");
		$facets = $rt->facets(self::neverResolveHandle());

		self::assertCount(1, $facets);
		// U+1F600 is 4 bytes in UTF-8, then space is 1 byte
		self::assertSame(5, $facets[0]['index']['byteStart']);
		self::assertSame(11, $facets[0]['index']['byteEnd']);
	}

	#[Test]
	public function cjkCharacterByteOffsets(): void
	{
		$rt = new RichText('こんにちは #世界');
		$facets = $rt->facets(self::neverResolveHandle());

		self::assertCount(1, $facets);
		self::assertSame('世界', $facets[0]['features'][0]['tag']);
		// Each CJK char is 3 bytes, 5 chars = 15 bytes, space = 1 byte
		self::assertSame(16, $facets[0]['index']['byteStart']);
		self::assertSame(23, $facets[0]['index']['byteEnd']);
	}

	#[Test]
	public function emptyTextProducesNoFacets(): void
	{
		$rt = new RichText('');
		self::assertSame([], $rt->facets(self::neverResolveHandle()));
	}

	#[Test]
	public function plainTextProducesNoFacets(): void
	{
		$rt = new RichText('Just plain text with no mentions, links, or hashtags');
		self::assertSame([], $rt->facets(self::neverResolveHandle()));
	}

	#[Test]
	public function linkInsideParenthesesIsTrimmed(): void
	{
		$rt = new RichText('See (https://example.com) for details');
		$facets = $rt->facets(self::neverResolveHandle());

		self::assertCount(1, $facets);
		self::assertSame('https://example.com', $facets[0]['features'][0]['uri']);
	}

	#[Test]
	public function detectsMultipleHashtagsAndLinks(): void
	{
		$rt = new RichText('#php https://example.com #coding');
		$facets = $rt->facets(self::neverResolveHandle());

		self::assertCount(3, $facets);
	}

	#[Test]
	public function resolvesMentionsViaCallback(): void
	{
		$rt = new RichText('Hi @alice.bsky.social and @bob.test');
		$facets = $rt->facets(static fn(string $handle): string => 'did:plc:'.str_replace(['.', '_'], '_', $handle));

		self::assertCount(2, $facets);
		self::assertSame('app.bsky.richtext.facet#mention', $facets[0]['features'][0]['$type']);
		self::assertSame('did:plc:alice_bsky_social', $facets[0]['features'][0]['did']);
		self::assertSame('did:plc:bob_test', $facets[1]['features'][0]['did']);
	}

	#[Test]
	public function unresolvedMentionIsSkippedSilently(): void
	{
		$rt = new RichText('Hi @ghost.bsky.social and @alice.bsky.social');
		$facets = $rt->facets(static function (string $handle): string {
			if ($handle === 'ghost.bsky.social') {
				throw new \Gimucco\Bluesky\Exception\NotFoundException(404, 'NotFound', 'no such handle');
			}
			return 'did:plc:alice';
		});

		// One mention resolved, the other (NotFound) silently skipped.
		self::assertCount(1, $facets);
		self::assertSame('did:plc:alice', $facets[0]['features'][0]['did']);
	}

	#[Test]
	public function nonNotFoundExceptionsBubbleUp(): void
	{
		$rt = new RichText('Hi @alice.bsky.social');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('network down');

		$rt->facets(static function (): string {
			throw new RuntimeException('network down');
		});
	}

	#[Test]
	public function resolvedMentionsAreCappedAtMaxLimit(): void
	{
		// Build text with 30 mentions; only MAX_MENTIONS (25) should resolve.
		$mentions = array_map(static fn(int $i): string => '@user'.$i.'.bsky.social', range(1, 30));
		$rt = new RichText(implode(' ', $mentions));
		$resolved = 0;
		$facets = $rt->facets(static function (string $handle) use (&$resolved): string {
			$resolved++;
			return 'did:plc:'.str_replace('.', '_', $handle);
		});

		self::assertSame(RichText::MAX_MENTIONS, \count($facets));
		self::assertSame(RichText::MAX_MENTIONS, $resolved);
	}

	/**
	 * Returns a resolver that fails the test if invoked — for cases where
	 * the text has no @mentions, so the resolver shouldn't be called at all.
	 *
	 * @return callable(string $handle): string
	 */
	private static function neverResolveHandle(): callable
	{
		return static function (string $handle): string {
			throw new LogicException('resolver should not be called, was called with: '.$handle);
		};
	}
}
