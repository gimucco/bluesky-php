<?php

declare(strict_types=1);

/**
 * Manual smoke test: upload a video, await processing, post it with the
 * resulting blob, then delete the post. Verifies the full video pipeline
 * end-to-end against a real Bluesky account — including the routing
 * through video.bsky.app with service-auth tokens.
 *
 * See tests/manual/README.md for setup.
 *
 * Usage:
 *   BLUESKY_DID=did:plc:yourtestaccount php tests/manual/video-upload.php /path/to/clip.mp4
 */

use Gimucco\Atproto\ClientConfig;
use Gimucco\Atproto\OAuthClient;
use Gimucco\Atproto\Storage\FileSessionStore;
use Gimucco\Atproto\Storage\FileStateStore;
use Gimucco\Bluesky\Client;
use Gimucco\Bluesky\EmbeddedVideo;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

require __DIR__.'/../../vendor/autoload.php';

$configPath = __DIR__.'/config.php';
if (!file_exists($configPath)) {
	fwrite(STDERR, "Missing tests/manual/config.php — see tests/manual/README.md\n");
	exit(1);
}
/** @var array{client_id: string, redirect_uri: string, scope: string, client_name: string, client_uri?: ?string, jwks_uri?: ?string, private_key_path: string, encryption_passphrase?: ?string, storage_dir?: ?string} $config */
$config = require $configPath;

$did = getenv('BLUESKY_DID');
if (!is_string($did) || $did === '') {
	fwrite(STDERR, "Set BLUESKY_DID env var to your test account's DID\n");
	exit(1);
}

$videoPath = $argv[1] ?? null;
if ($videoPath === null || !is_file($videoPath)) {
	fwrite(STDERR, "Usage: BLUESKY_DID=... php tests/manual/video-upload.php <path-to-mp4>\n");
	exit(1);
}

$privateKey = (string) file_get_contents($config['private_key_path']);
$factory = new HttpFactory();
$storageDir = $config['storage_dir'] ?? __DIR__.'/storage';
$passphrase = $config['encryption_passphrase'] ?? null;

$oauth = new OAuthClient(
	config: new ClientConfig(
		clientId: $config['client_id'],
		redirectUri: $config['redirect_uri'],
		scope: $config['scope'],
		clientName: $config['client_name'],
		jwksUri: $config['jwks_uri'] ?? null,
		privateKey: $privateKey,
		encryptionPassphrase: $passphrase,
	),
	sessionStore: new FileSessionStore($storageDir.'/sessions', $passphrase),
	stateStore: new FileStateStore($storageDir.'/states', $passphrase),
	httpClient: new GuzzleClient(['timeout' => 30]),
	requestFactory: $factory,
	streamFactory: $factory,
);

$session = $oauth->restoreSession($did);
if ($session === null) {
	fwrite(STDERR, "No stored session for {$did} — run examples/login.php first\n");
	exit(1);
}

$client = new Client($session);

$bytes = (string) file_get_contents($videoPath);

// Step 1: exercise the BlobRef-returning path so we can assert the blob
// is well-formed before posting. Combines upload + await internally; the
// service-auth round-trip + curl call to video.bsky.app happens here.
echo '1. Uploading video ('.strlen($bytes).' bytes) and awaiting processing... ';
$videoBlob = $client->uploadVideo($bytes);
echo "OK (blob: {$videoBlob->link}, {$videoBlob->size} bytes)\n";

echo '2. Posting with video embed... ';
$post = $client->post(
	'Smoke test video — will be deleted shortly. '.date('c'),
	video: new EmbeddedVideo($videoBlob, alt: 'Smoke test clip'),
);
echo "OK ({$post->uri})\n";

echo '3. Deleting post... ';
sleep(2);
$client->deletePost($post->uri);
echo "OK\n";

echo "Smoke test passed.\n";
