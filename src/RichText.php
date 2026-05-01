<?php

declare(strict_types=1);

namespace Gimucco\Bluesky;

use Gimucco\Bluesky\Exception\NotFoundException;

final class RichText
{
	/**
	 * Maximum number of @mentions to resolve per post. Above this, additional
	 * @-prefixed strings are left as plain text. Bluesky's official client
	 * applies a similar cap; this also bounds rate-limit exposure when posts
	 * accidentally contain many @-like substrings.
	 */
	public const MAX_MENTIONS = 25;

	/**
	 * `@handle` — handles are DNS-style (alphanumerics + dots), at least one
	 * dot, and must not be preceded by an alphanumeric / dot / underscore
	 * (so `email@host.com` doesn't get parsed as a mention of `host.com`).
	 */
	private const PATTERN_MENTION = '/(?<![a-zA-Z0-9._])@([a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?\.([a-zA-Z]{2,}))/u';

	/**
	 * `https?://...` — greedy match terminated by whitespace or punctuation.
	 * The trailing-char exclusion handles "https://x.com." (sentence period)
	 * and "(https://x.com)" (parenthetical) by trimming the trailing punctuation.
	 */
	private const PATTERN_LINK = '/https?:\/\/[^\s<>\[\]()"\x{2018}\x{2019}\x{201c}\x{201d}]+[^\s<>\[\]()"\x{2018}\x{2019}\x{201c}\x{201d}.,;:!?\x{2026}]/u';

	/**
	 * `#hashtag` — Unicode-aware (CJK, accents, etc). Negative lookbehind
	 * prevents matches inside words (`test#nope`).
	 */
	private const PATTERN_HASHTAG = '/(?<![a-zA-Z0-9_])#([a-zA-Z\p{L}\p{M}][a-zA-Z0-9\p{L}\p{M}_]*)/u';

	public function __construct(private readonly string $text) {}

	public function plainText(): string
	{
		return $this->text;
	}

	/**
	 * Build the facets array for this text, resolving mentions via the given
	 * handle resolver.
	 *
	 * @param callable(string $handle): string $resolveHandle Returns the DID for a given handle.
	 *        Should throw NotFoundException when the handle doesn't exist; any other exception
	 *        bubbles up.
	 * @return list<array<string, mixed>>
	 */
	public function facets(callable $resolveHandle): array
	{
		return [
			...$this->detectMentions($resolveHandle),
			...$this->detectLinks(),
			...$this->detectHashtags(),
		];
	}

	/**
	 * @param callable(string $handle): string $resolveHandle
	 * @return list<array<string, mixed>>
	 */
	private function detectMentions(callable $resolveHandle): array
	{
		$facets = [];
		if (preg_match_all(self::PATTERN_MENTION, $this->text, $matches, PREG_OFFSET_CAPTURE) === false) {
			return [];
		}

		$resolved = 0;
		foreach ($matches[0] as $i => $match) {
			if ($resolved >= self::MAX_MENTIONS) {
				break;
			}
			$fullMatch = $match[0];
			$handle = $matches[1][$i][0];
			$byteStart = $match[1];
			$byteEnd = $byteStart + \strlen($fullMatch);

			try {
				$did = $resolveHandle($handle);
			} catch (NotFoundException) {
				// Unknown handle — leave as plain text. Other failures (network,
				// auth, rate-limit) bubble up so callers see real problems.
				continue;
			}

			$facets[] = [
				'index' => [
					'byteStart' => $byteStart,
					'byteEnd' => $byteEnd,
				],
				'features' => [
					[
						'$type' => 'app.bsky.richtext.facet#mention',
						'did' => $did,
					],
				],
			];
			$resolved++;
		}

		return $facets;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function detectLinks(): array
	{
		$facets = [];
		if (preg_match_all(self::PATTERN_LINK, $this->text, $matches, PREG_OFFSET_CAPTURE) === false) {
			return [];
		}

		foreach ($matches[0] as $match) {
			$url = $match[0];
			$byteStart = $match[1];
			$byteEnd = $byteStart + \strlen($url);

			$facets[] = [
				'index' => [
					'byteStart' => $byteStart,
					'byteEnd' => $byteEnd,
				],
				'features' => [
					[
						'$type' => 'app.bsky.richtext.facet#link',
						'uri' => $url,
					],
				],
			];
		}

		return $facets;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function detectHashtags(): array
	{
		$facets = [];
		if (preg_match_all(self::PATTERN_HASHTAG, $this->text, $matches, PREG_OFFSET_CAPTURE) === false) {
			return [];
		}

		foreach ($matches[0] as $i => $match) {
			$fullMatch = $match[0];
			$tag = $matches[1][$i][0];
			$byteStart = $match[1];
			$byteEnd = $byteStart + \strlen($fullMatch);

			$facets[] = [
				'index' => [
					'byteStart' => $byteStart,
					'byteEnd' => $byteEnd,
				],
				'features' => [
					[
						'$type' => 'app.bsky.richtext.facet#tag',
						'tag' => $tag,
					],
				],
			];
		}

		return $facets;
	}
}
