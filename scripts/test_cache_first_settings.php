<?php
declare(strict_types=1);

function settings_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$module = dirname(__DIR__) . '/app/cache_first_settings.php';
if (!is_file($module)) {
    throw new RuntimeException('CACHE_FIRST_SETTINGS_MODULE_MISSING');
}
require $module;

$legacy = portal_cache_first_settings_decode('{"full_reconcile_seconds":60}');
settings_assert($legacy === [
    'full_reconcile_seconds' => 60,
    'writeback_idle_minutes' => 60,
], 'Legacy settings must preserve reconcile seconds and default idle write-back to 60 minutes.');

$configured = portal_cache_first_settings_decode('{"full_reconcile_seconds":120,"writeback_idle_minutes":90}');
settings_assert($configured === [
    'full_reconcile_seconds' => 120,
    'writeback_idle_minutes' => 90,
], 'Explicit settings must keep seconds and minutes separate.');

settings_assert(portal_cache_first_writeback_idle_minutes('5') === 5, 'Minimum idle write-back interval must be accepted.');
settings_assert(portal_cache_first_writeback_idle_minutes('1440') === 1440, 'Maximum idle write-back interval must be accepted.');

$encoded = portal_cache_first_settings_encode(120, 90);
settings_assert($encoded === "{\"full_reconcile_seconds\":120,\"writeback_idle_minutes\":90}\n", 'Settings must use one canonical allowlisted JSON payload.');
settings_assert(portal_cache_first_settings_decode($encoded) === $configured, 'Canonical settings payload must round-trip exactly.');

foreach (['', '4', '1441', '60.0', '-1', ' 60'] as $invalid) {
    try {
        portal_cache_first_writeback_idle_minutes($invalid);
        throw new RuntimeException('Expected invalid idle interval rejection for: ' . json_encode($invalid));
    } catch (InvalidArgumentException $exception) {
        settings_assert($exception->getMessage() === 'CACHE_FIRST_IDLE_MINUTES_INVALID', 'Idle interval must use a stable validation error.');
    }
}

echo "[OK] Cache-first reconcile-seconds and writeback-idle-minutes settings contract passed.\n";
