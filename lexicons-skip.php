<?php

declare(strict_types=1);

/**
 * Lexicon NSIDs to skip during code generation.
 * Supports glob patterns (e.g., "chat.bsky.*").
 */
return [
	'com.atproto.sync.*',
	'chat.bsky.*',
	'tools.ozone.*',
	'com.atproto.admin.*',
	'com.atproto.temp.*',
	'com.atproto.moderation.*',
	'app.bsky.unspecced.*',
];
