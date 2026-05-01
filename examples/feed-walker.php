<?php

declare(strict_types=1);

/**
 * Walk the timeline using a generated paginator.
 *
 * Usage:
 *   php examples/feed-walker.php <your-did>
 *
 * Requires a session already created via examples/login.php.
 */

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$count = 0;
foreach ($client->feed->paginateTimeline(limit: 50, maxItems: 100) as $item) {
	$author = $item->post->author->handle;
	$text = is_array($item->post->record) && isset($item->post->record['text']) && is_string($item->post->record['text'])
		? $item->post->record['text']
		: '';
	$preview = mb_substr($text, 0, 80);
	echo "@{$author}: {$preview}\n";
	$count++;
}

echo "Done. Walked {$count} post(s).\n";

// The same auto-paginator pattern works for any cursor-based endpoint:
//
//   foreach ($client->graph->paginateFollowers('alice.bsky.social') as $follower) {
//       echo $follower->handle."\n";
//   }
//
//   foreach ($client->notification->paginateNotifications() as $notif) { ... }
