<?php

declare(strict_types=1);

/**
 * Moderation actions: block and mute.
 *
 *   block — public, persistent record. Stops mutual interaction.
 *   mute  — local-only. The muted user is unaware; you stop seeing their posts.
 *
 * Both methods accept a handle, a DID, or the typed Did/Handle value objects.
 * For block, the handle is auto-resolved to a DID via one extra API call.
 *
 * Usage:
 *   php examples/moderate.php <your-did> <action> <target-handle-or-did>
 *
 *   action = block | unblock | mute | unmute
 *
 * Example:
 *   php examples/moderate.php did:plc:me block troll.bsky.social
 *   php examples/moderate.php did:plc:me mute spammer.bsky.social
 */

require __DIR__.'/bootstrap.php';

$client = examples_client_for_did(examples_did());

$action = examples_arg(2);
$target = examples_arg(3);

if ($action === null || $target === null) {
	fwrite(STDERR, "Usage: php examples/moderate.php <your-did> <action> <target>\n");
	fwrite(STDERR, "  action: block | unblock | mute | unmute\n");
	exit(1);
}

switch ($action) {
	case 'block':
		$ref = $client->block($target);
		echo "Blocked {$target}\n  Block record: {$ref->uri}\n";
		echo "  (save this URI to unblock later)\n";
		break;

	case 'unblock':
		// $target should be the AT-URI of the block record
		$client->unblock($target);
		echo "Removed block record {$target}\n";
		break;

	case 'mute':
		$client->mute($target);
		echo "Muted {$target} (local only — they aren't notified)\n";
		break;

	case 'unmute':
		$client->unmute($target);
		echo "Unmuted {$target}\n";
		break;

	default:
		fwrite(STDERR, "Unknown action: {$action}\n");
		exit(1);
}
