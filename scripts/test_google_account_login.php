<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function google_login_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

google_login_assert(function_exists('portal_normalize_google_email'), 'Google email normalizer is missing.');
google_login_assert(portal_normalize_google_email('  Gary.Example+Portal@GMAIL.com ') === 'gary.example+portal@gmail.com', 'Google email normalization failed.');
google_login_assert(portal_normalize_google_email('not-an-email') === '', 'Invalid Google email must be rejected.');

google_login_assert(function_exists('portal_google_login_configured'), 'Google login configuration gate is missing.');
google_login_assert(!portal_google_login_configured(['google_oauth_client_id' => '', 'google_oauth_client_secret' => '', 'google_oauth_redirect_uri' => '', 'google_ca_bundle' => '']), 'Incomplete Google login configuration must stay disabled.');
google_login_assert(portal_google_login_configured([
    'google_oauth_client_id' => '123.apps.googleusercontent.com',
    'google_oauth_client_secret' => 'secret',
    'google_oauth_redirect_uri' => 'https://example.test/portal/?action=google_callback',
    'google_ca_bundle' => __FILE__,
]), 'Complete Google login configuration must enable the feature.');
google_login_assert(function_exists('portal_google_curl_tls_options'), 'Google TLS option helper is missing.');
$tlsOptions = portal_google_curl_tls_options(__FILE__);
google_login_assert(($tlsOptions[CURLOPT_CAINFO] ?? '') === realpath(__FILE__), 'Google TLS must pin the configured CA bundle.');
google_login_assert(($tlsOptions[CURLOPT_SSL_VERIFYPEER] ?? false) === true, 'Google TLS peer verification must stay enabled.');
google_login_assert(($tlsOptions[CURLOPT_SSL_VERIFYHOST] ?? 0) === 2, 'Google TLS hostname verification must stay enabled.');

google_login_assert(function_exists('portal_google_validate_identity'), 'Google identity validation helper is missing.');
$identity = portal_google_validate_identity([
    'aud' => '123.apps.googleusercontent.com',
    'iss' => 'https://accounts.google.com',
    'exp' => time() + 300,
    'email' => 'Gary.Example@gmail.com',
    'email_verified' => 'true',
    'sub' => '109876543210',
    'nonce' => 'expected-nonce',
], '123.apps.googleusercontent.com', 'expected-nonce');
google_login_assert($identity['email'] === 'gary.example@gmail.com' && $identity['subject'] === '109876543210', 'Verified identity was not normalized.');

foreach ([
    ['aud' => 'wrong.apps.googleusercontent.com'],
    ['iss' => 'https://evil.example'],
    ['exp' => time() - 1],
    ['email_verified' => 'false'],
    ['nonce' => 'wrong'],
] as $override) {
    $claims = array_merge([
        'aud' => '123.apps.googleusercontent.com', 'iss' => 'accounts.google.com', 'exp' => time() + 300,
        'email' => 'gary@example.com', 'email_verified' => 'true', 'sub' => '109876543210', 'nonce' => 'expected-nonce',
    ], $override);
    $rejected = false;
    try {
        portal_google_validate_identity($claims, '123.apps.googleusercontent.com', 'expected-nonce');
    } catch (RuntimeException) {
        $rejected = true;
    }
    google_login_assert($rejected, 'Invalid Google identity claims must be rejected.');
}

$index = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php');
$migration = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '013_google_account_login.sql';
google_login_assert(str_contains((string) $index, "'google_login', 'google_callback'"), 'Google login routes are missing.');
google_login_assert(is_file($migration), 'Google account binding migration is missing.');
google_login_assert(str_contains((string) file_get_contents($migration), 'google_email_normalized'), 'Migration must add a normalized Google email binding.');
google_login_assert(str_contains((string) file_get_contents($migration), 'google_subject'), 'Migration must bind the immutable Google subject.');

echo "[OK] Google account login contracts passed.\n";
