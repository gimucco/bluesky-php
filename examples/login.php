<?php

declare(strict_types=1);

/**
 * One-time OAuth login starter.
 *
 * Run this from a browser on a host that matches your client_metadata.json
 * (or http://localhost during development).
 *
 *   php -S localhost:8080 -t examples
 *   open http://localhost:8080/login.php
 *
 * Submits the user's handle (or none, for "server-first" flow), resolves
 * identity, and redirects to the Bluesky authorization server. The
 * authorization server then redirects back to your `redirect_uri`, which
 * should serve examples/callback.php.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	?>
	<!DOCTYPE html>
	<html lang="en">
	<head>
		<meta charset="utf-8">
		<title>Sign in with Bluesky</title>
		<style>
			body { font-family: system-ui, sans-serif; max-width: 420px; margin: 80px auto; padding: 0 20px; }
			input, button { width: 100%; padding: 10px; box-sizing: border-box; font-size: 1em; margin-top: 8px; }
			button { background: #0085ff; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
			.hint { color: #666; font-size: 0.85em; }
		</style>
	</head>
	<body>
		<h1>Sign in with Bluesky</h1>
		<form method="post">
			<label>Your handle (optional):</label>
			<input type="text" name="handle" placeholder="alice.bsky.social">
			<p class="hint">Leave blank to choose your account on the next page.</p>
			<button type="submit">Continue</button>
		</form>
	</body>
	</html>
	<?php
	exit;
}

$handle = isset($_POST['handle']) && is_string($_POST['handle']) ? trim($_POST['handle']) : '';

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
	$authUrl = $oauthClient->beginAuthorization($handle !== '' ? $handle : null);
	header('Location: '.$authUrl);
	exit;
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Login error: '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
	exit(1);
}
