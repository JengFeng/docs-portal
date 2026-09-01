<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function bridge_settings_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'twwater-bridge-settings-' . bin2hex(random_bytes(6)) . '.json';
try {
    bridge_settings_assert(portal_bridge_reconcile_interval('30') === 30, '30 seconds must be accepted.');
    bridge_settings_assert(portal_bridge_reconcile_interval('60') === 60, '60 seconds must be accepted.');
    bridge_settings_assert(portal_bridge_reconcile_interval('3600') === 3600, '3600 seconds must be accepted.');
    foreach (['', '29', '3601', '60.5', 'abc'] as $invalid) {
        try {
            portal_bridge_reconcile_interval($invalid);
            throw new RuntimeException('Invalid interval was accepted: ' . $invalid);
        } catch (RuntimeException $exception) {
            bridge_settings_assert($exception->getMessage() === '漏失校正秒數必須介於 30 到 3600 秒。', 'Invalid interval must use the expected validation error.');
        }
    }

    portal_bridge_settings_write($temporary, 60);
    $stored = json_decode((string) file_get_contents($temporary), true, 8, JSON_THROW_ON_ERROR);
    bridge_settings_assert($stored === ['full_reconcile_seconds' => 60], 'Settings file must contain only the allowlisted integer.');
    bridge_settings_assert(portal_bridge_settings_read($temporary, 300) === 60, 'Stored interval must be read back.');

    file_put_contents($temporary, '{invalid', LOCK_EX);
    bridge_settings_assert(portal_bridge_settings_read($temporary, 300) === 300, 'Invalid JSON must fall back safely.');
    file_put_contents($temporary, '{"full_reconcile_seconds":29}', LOCK_EX);
    bridge_settings_assert(portal_bridge_settings_read($temporary, 300) === 300, 'Out-of-range setting must fall back safely.');

    $indexSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php');
    foreach ([
        "'admin_bridge_settings'",
        'portal_handle_admin_bridge_settings',
        'portal_assert_csrf()',
        'name="full_reconcile_seconds"',
        '漏失校正頻率',
    ] as $needle) {
        bridge_settings_assert(str_contains($indexSource, $needle), 'Missing admin settings contract: ' . $needle);
    }

    $bridgeSource = (string) file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'document_bridge.ps1');
    foreach ([
        '$SettingsPath',
        'Get-EffectiveFullReconcileSeconds',
        '$effectiveFullReconcileSeconds',
        'bridge_setting_changed',
        'TotalSeconds -ge $effectiveFullReconcileSeconds',
    ] as $needle) {
        bridge_settings_assert(str_contains($bridgeSource, $needle), 'Missing dynamic bridge contract: ' . $needle);
    }

    echo "[OK] Bridge reconciliation settings contracts passed.\n";
} finally {
    if (is_file($temporary)) {
        unlink($temporary);
    }
}
