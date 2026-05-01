<?php

declare(strict_types=1);

/**
 * Example OAuth client configuration. Copy to config.php and edit.
 *
 * The AT Protocol uses OAuth 2.1 with PKCE + DPoP. There is no
 * identifier+password flow. You must:
 *
 *   1. Generate an ES256 private key:
 *        openssl ecparam -genkey -name prime256v1 -noout -out private.pem
 *   2. Host two static JSON files at HTTPS URLs:
 *        client-metadata.json  (generate via atproto-php's bin/generate-metadata)
 *        jwks.json
 *   3. Run examples/login.php to start the browser flow
 *   4. After completing the callback, the user's DID is persisted
 *      in the session store and can be reused via $oauthClient->restoreSession($did).
 *
 * See vendor/gimucco/atproto-php/README.md for the full setup walkthrough.
 */

$env = static function (string $name, ?string $default = null): ?string {
	$v = getenv($name);
	return is_string($v) && $v !== '' ? $v : $default;
};

return [
	// HTTPS URL where client-metadata.json is hosted (must match exactly).
	'client_id' => $env('ATPROTO_CLIENT_ID', 'https://your-app.com/atproto/client-metadata.json'),

	// HTTPS callback URL (or http://localhost for development).
	'redirect_uri' => $env('ATPROTO_REDIRECT_URI', 'https://your-app.com/atproto/callback'),

	// Must include "atproto". "transition:generic" grants Bluesky API access.
	'scope' => 'atproto transition:generic',

	// Human-readable application name shown to users on the consent screen.
	'client_name' => 'My Bluesky App',

	// Optional homepage URL.
	'client_uri' => $env('ATPROTO_CLIENT_URI'),

	// HTTPS URL where jwks.json is hosted.
	'jwks_uri' => $env('ATPROTO_JWKS_URI', 'https://your-app.com/atproto/jwks.json'),

	// Path to the ES256 private key (PEM). Keep this file secret.
	'private_key_path' => $env('ATPROTO_PRIVATE_KEY', __DIR__.'/private.pem'),

	// Passphrase used to encrypt tokens and DPoP keys at rest.
	// If null, storage is unencrypted (a warning will be logged).
	'encryption_passphrase' => $env('ATPROTO_PASSPHRASE'),

	// Where to store sessions and OAuth state.
	'storage_dir' => $env('ATPROTO_STORAGE_DIR', __DIR__.'/storage'),
];
