<?php

declare(strict_types=1);

/**
 * Walk recent notifications and mark them all as read.
 *
 *   - paginateNotifications() yields one notification at a time, walking pages
 *     automatically (capped here at 50 with maxItems).
 *   - notification->updateSeen() marks everything before the given timestamp
 *     as seen — equivalent to "mark all as read" if you pass `now`.
 *
 * Usage:
 *   php examples/notifications.php <your-did>
 */

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$count = 0;
foreach ($client->notification->paginateNotifications(maxItems: 50) as $notif) {
	$author = $notif->author->handle;
	$status = $notif->isRead ? 'read' : 'unread';

	echo "[{$status}] {$notif->indexedAt} — {$notif->reason} from @{$author}\n";
	$count++;
}

echo "\nWalked {$count} notification(s).\n";

if ($count > 0) {
	echo "\nMarking all as seen... ";
	// updateSeen takes an ISO-8601 string per the lexicon.
	$client->notification->updateSeen((new DateTimeImmutable())->format('c'));
	echo "OK\n";
}
