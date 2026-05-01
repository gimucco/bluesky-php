<?php

declare(strict_types=1);

/**
 * Manual smoke test: create a text post, verify it exists via getPost(),
 * then delete it. Requires a configured Bluesky test account.
 *
 * See tests/manual/README.md for setup.
 *
 * Usage:
 *   BLUESKY_DID=did:plc:yourtestaccount php tests/manual/post-and-delete.php
 */

use Gimucco\Atproto\ClientConfig;
use Gimucco\Atproto\OAuthClient;
use Gimucco\Atproto\Storage\FileSessionStore;
use Gimucco\Atproto\Storage\FileStateStore;
use Gimucco\Bluesky\Client;
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

echo '1. Creating post... ';
$post = $client->post('Smoke test post — will be deleted in ~5 seconds. '.date('c'));
echo "OK ({$post->uri})\n";

echo '2. Reading post back via getPost()... ';
sleep(2);  // Give the API a moment to index
$fetched = $client->getPost($post->uri);
if ($fetched->uri !== $post->uri) {
	fwrite(STDERR, "MISMATCH: got {$fetched->uri}\n");
	exit(1);
}
echo "OK\n";

echo '3. Deleting post... ';
$client->deletePost($post->uri);
echo "OK\n";

echo "Smoke test passed.\n";
