<?php
declare(strict_types=1);

/** @return array{full_reconcile_seconds:int,writeback_idle_minutes:int} */
function portal_cache_first_settings_decode(string $raw): array
{
    try {
        $settings = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new InvalidArgumentException('CACHE_FIRST_SETTINGS_INVALID', 0, $exception);
    }
    if (!is_array($settings) || !array_key_exists('full_reconcile_seconds', $settings)) {
        throw new InvalidArgumentException('CACHE_FIRST_SETTINGS_INVALID');
    }
    $full = portal_cache_first_reconcile_seconds($settings['full_reconcile_seconds']);
    $idle = array_key_exists('writeback_idle_minutes', $settings)
        ? portal_cache_first_writeback_idle_minutes($settings['writeback_idle_minutes'])
        : 60;
    return [
        'full_reconcile_seconds' => $full,
        'writeback_idle_minutes' => $idle,
    ];
}

function portal_cache_first_reconcile_seconds(mixed $value): int
{
    if (!is_int($value) || $value < 30 || $value > 3600) {
        throw new InvalidArgumentException('CACHE_FIRST_RECONCILE_SECONDS_INVALID');
    }
    return $value;
}

function portal_cache_first_settings_encode(mixed $fullReconcileSeconds, mixed $writebackIdleMinutes): string
{
    $payload = [
        'full_reconcile_seconds' => portal_cache_first_reconcile_seconds($fullReconcileSeconds),
        'writeback_idle_minutes' => portal_cache_first_writeback_idle_minutes($writebackIdleMinutes),
    ];
    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

function portal_cache_first_writeback_idle_minutes(mixed $value): int
{
    if (is_int($value)) {
        $text = (string) $value;
    } elseif (is_string($value)) {
        $text = $value;
    } else {
        throw new InvalidArgumentException('CACHE_FIRST_IDLE_MINUTES_INVALID');
    }
    if (preg_match('/\A[0-9]+\z/D', $text) !== 1) {
        throw new InvalidArgumentException('CACHE_FIRST_IDLE_MINUTES_INVALID');
    }
    $minutes = (int) $text;
    if ($minutes < 5 || $minutes > 1440) {
        throw new InvalidArgumentException('CACHE_FIRST_IDLE_MINUTES_INVALID');
    }
    return $minutes;
}
