<?php

declare(strict_types=1);

/**
 * Demonstrates the basic read patterns:
 *
 * - myProfile()        — your own profile (no DID lookup needed)
 * - actor->getProfile() — anyone's profile by handle or DID
 * - getPost(uri)       — single post by AT-URI
 *
 * Usage:
 *   php examples/profile-and-fetch.php <your-did> [other-handle]
 */

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$me = $client->myProfile();
echo "Me: @{$me->handle}";
if ($me->displayName !== null) {
	echo " ({$me->displayName})";
}
echo "\n  did: {$me->did}\n";
echo "  followers: ".($me->followersCount ?? 0).", following: ".($me->followsCount ?? 0).", posts: ".($me->postsCount ?? 0)."\n";

$other = examples_arg(2);
if ($other !== null) {
	$them = $client->actor->getProfile($other);
	echo "\nThem: @{$them->handle}";
	if ($them->displayName !== null) {
		echo " ({$them->displayName})";
	}
	echo "\n  did: {$them->did}\n";

	// Fetch their most recent post if available.
	$feed = $client->feed->getAuthorFeed($them->did, limit: 1);
	if ($feed->feed !== []) {
		$first = $feed->feed[0]->post;
		echo "\nMost recent post: {$first->uri}\n";

		// Round-trip via getPost() to demonstrate the helper:
		$single = $client->getPost($first->uri);
		$text = is_array($single->record) && isset($single->record['text']) && is_string($single->record['text'])
			? $single->record['text']
			: '';
		echo "  text: ".mb_substr($text, 0, 140)."\n";
	}
}
