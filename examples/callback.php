<?php

declare(strict_types=1);

/**
 * OAuth callback — finishes the flow started by examples/login.php.
 *
 * Your client_metadata.json's redirect_uri must point at this script. After
 * a successful exchange the session is persisted in the session store and
 * the resulting DID is shown — copy it for the other examples.
 */

use Gimucco\Atproto\ClientConfig;
use Gimucco\Atproto\OAuthClient;
use Gimucco\Atproto\Storage\FileSessionStore;
use Gimucco\Atproto\Storage\FileStateStore;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

require __DIR__.'/../vendor/autoload.php';

$configPath = __DIR__.'/config.php';
if (!file_exists($configPath)) {
	http_response_code(500);
	echo 'Missing examples/config.php — copy config.example.php to config.php and edit it.';
	exit(1);
}

/** @var array{client_id: string, redirect_uri: string, scope: string, client_name: string, client_uri?: ?string, jwks_uri?: ?string, private_key_path: string, encryption_passphrase?: ?string, storage_dir?: ?string} $config */
$config = require $configPath;

$code = isset($_GET['code']) && is_string($_GET['code']) ? $_GET['code'] : '';
$state = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : '';
$iss = isset($_GET['iss']) && is_string($_GET['iss']) ? $_GET['iss'] : '';
$error = isset($_GET['error']) && is_string($_GET['error']) ? $_GET['error'] : '';

if ($error !== '') {
	http_response_code(400);
	$desc = isset($_GET['error_description']) && is_string($_GET['error_description'])
		? $_GET['error_description']
		: 'Unknown error';
	echo 'Authorization denied: '.htmlspecialchars($error.' — '.$desc, ENT_QUOTES, 'UTF-8');
	exit(1);
}

if ($code === '' || $state === '' || $iss === '') {
	http_response_code(400);
	echo 'Missing required callback parameters (code, state, iss).';
	exit(1);
}

$privateKeyPath = $config['private_key_path'];
if (!is_file($privateKeyPath)) {
	http_response_code(500);
	echo 'Private key not found at: '.htmlspecialchars($privateKeyPath, ENT_QUOTES, 'UTF-8');
	exit(1);
}
$privateKey = file_get_contents($privateKeyPath);
if ($privateKey === false) {
	http_response_code(500);
	echo 'Cannot read private key';
	exit(1);
}

$clientConfig = new ClientConfig(
	clientId: $config['client_id'],
	redirectUri: $config['redirect_uri'],
	scope: $config['scope'],
	clientName: $config['client_name'],
	clientUri: $config['client_uri'] ?? null,
	jwksUri: $config['jwks_uri'] ?? null,
	privateKey: $privateKey,
	encryptionPassphrase: $config['encryption_passphrase'] ?? null,
);

$storageDir = $config['storage_dir'] ?? __DIR__.'/storage';
$passphrase = $config['encryption_passphrase'] ?? null;

$factory = new HttpFactory();
$oauthClient = new OAuthClient(
	config: $clientConfig,
	sessionStore: new FileSessionStore($storageDir.'/sessions', $passphrase),
	stateStore: new FileStateStore($storageDir.'/states', $passphrase),
	httpClient: new GuzzleClient(['timeout' => 30]),
	requestFactory: $factory,
	streamFactory: $factory,
);

try {
	$session = $oauthClient->completeAuthorization($code, $state, $iss);
	?>
	<!DOCTYPE html>
	<html lang="en">
	<head>
		<meta charset="utf-8">
		<title>Signed in</title>
		<style>
			body { font-family: system-ui, sans-serif; max-width: 520px; margin: 80px auto; padding: 0 20px; }
			code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
			.success { color: #080; }
		</style>
	</head>
	<body>
		<h1 class="success">Signed in</h1>
		<p>Handle: <strong><?= htmlspecialchars($session->handle, ENT_QUOTES, 'UTF-8') ?></strong></p>
		<p>DID: <code><?= htmlspecialchars($session->did, ENT_QUOTES, 'UTF-8') ?></code></p>
		<p>Use this DID with the CLI examples:</p>
		<pre>BLUESKY_DID=<?= htmlspecialchars($session->did, ENT_QUOTES, 'UTF-8') ?> php examples/post-text.php</pre>
	</body>
	</html>
	<?php
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Authorization error: '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
	exit(1);
}
