<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the example scripts.
 *
 * Provides two factory functions:
 *
 *   examples_oauth_client(): builds an OAuthClient from examples/config.php.
 *   examples_client_for_did(string $did): builds a Bluesky Client for the
 *       given DID, restoring its persisted session via the OAuthClient.
 *
 * Each example script calls examples_client_for_did(), passing a DID supplied
 * via the BLUESKY_DID env var or the first CLI argument.
 */

use Gimucco\Atproto\ClientConfig;
use Gimucco\Atproto\OAuthClient;
use Gimucco\Atproto\Storage\FileSessionStore;
use Gimucco\Atproto\Storage\FileStateStore;
use Gimucco\Bluesky\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

require_once __DIR__.'/../vendor/autoload.php';

function examples_oauth_client(): OAuthClient
{
	$configPath = __DIR__.'/config.php';
	if (!file_exists($configPath)) {
		fwrite(STDERR, "Missing examples/config.php — copy config.example.php to config.php and edit it.\n");
		exit(1);
	}

	/** @var array{client_id: string, redirect_uri: string, scope: string, client_name: string, client_uri?: ?string, jwks_uri?: ?string, private_key_path: string, encryption_passphrase?: ?string, storage_dir?: ?string} $config */
	$config = require $configPath;

	$privateKeyPath = $config['private_key_path'];
	if (!is_file($privateKeyPath)) {
		fwrite(STDERR, "Private key not found at: {$privateKeyPath}\n");
		fwrite(STDERR, "Generate one with: openssl ecparam -genkey -name prime256v1 -noout -out private.pem\n");
		exit(1);
	}
	$privateKey = file_get_contents($privateKeyPath);
	if ($privateKey === false) {
		fwrite(STDERR, "Cannot read private key at: {$privateKeyPath}\n");
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

	return new OAuthClient(
		config: $clientConfig,
		sessionStore: new FileSessionStore($storageDir.'/sessions', $passphrase),
		stateStore: new FileStateStore($storageDir.'/states', $passphrase),
		httpClient: new GuzzleClient(['timeout' => 30]),
		requestFactory: $factory,
		streamFactory: $factory,
	);
}

function examples_client_for_did(string $did): Client
{
	if ($did === '') {
		fwrite(STDERR, "Missing DID. Set BLUESKY_DID env var or pass it as the first argument.\n");
		fwrite(STDERR, "Run examples/login.php in a browser first to create a session.\n");
		exit(1);
	}

	$session = examples_oauth_client()->restoreSession($did);
	if ($session === null) {
		fwrite(STDERR, "No stored session for DID: {$did}\n");
		fwrite(STDERR, "Run examples/login.php in a browser first.\n");
		exit(1);
	}

	return new Client($session);
}

/**
 * Read positional CLI argument $position from $_SERVER['argv'], or null if missing.
 *
 * We use $_SERVER['argv'] rather than the global $argv because PHPStan strict
 * mode can't prove $argv exists in arbitrary scopes (it's only auto-defined
 * in CLI mode at the top of the entry script). $_SERVER['argv'] is mirrored
 * from the same source and is universally available.
 */
function examples_arg(int $position): ?string
{
	$argv = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
	$value = $argv[$position] ?? null;
	return is_string($value) ? $value : null;
}

/**
 * Resolve a DID from the BLUESKY_DID env var or the first positional argument.
 */
function examples_did(): string
{
	$envDid = getenv('BLUESKY_DID');
	if (is_string($envDid) && $envDid !== '') {
		return $envDid;
	}
	return examples_arg(1) ?? '';
}
