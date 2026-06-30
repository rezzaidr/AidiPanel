<?php
declare(strict_types=1);

namespace Core;

final class WebDeliveryStatus
{
    private const MAX_SITES = 100000;

    private const KEYS = [
        'schema',
        'checked_at',
        'gzip',
        'brotli_module',
        'brotli_config',
        'https_sites_total',
        'http2_configured',
        'http2_unknown',
        'http3_module',
        'http3_configured',
        'http3_partial',
        'http3_not_configured',
        'http3_unknown',
        'udp_443',
        'external_reachability',
    ];

    public static function parse(string $output): array
    {
        $values = [];
        $lines = preg_split('/\r?\n/', trim($output));
        if (!is_array($lines) || $lines === [] || $lines === ['']) {
            return self::unavailable();
        }

        foreach ($lines as $line) {
            if ($line === '' || !str_contains($line, '=')) {
                return self::unavailable();
            }
            [$key, $value] = explode('=', $line, 2);
            if (!in_array($key, self::KEYS, true) || array_key_exists($key, $values)) {
                return self::unavailable();
            }
            $values[$key] = $value;
        }

        if (count($values) !== count(self::KEYS)) {
            return self::unavailable();
        }
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $values)) {
                return self::unavailable();
            }
        }
        if ($values['schema'] !== '1' || !self::validTimestamp($values['checked_at'])) {
            return self::unavailable();
        }

        $configStates = ['configured', 'not_configured', 'unknown'];
        $moduleStates = ['detected', 'not_detected', 'unknown'];
        if (!in_array($values['gzip'], $configStates, true)
            || !in_array($values['brotli_module'], $moduleStates, true)
            || !in_array($values['brotli_config'], $configStates, true)
            || !in_array($values['http3_module'], $moduleStates, true)
            || !in_array($values['udp_443'], ['listening', 'not_listening', 'unknown'], true)
            || $values['external_reachability'] !== 'not_tested') {
            return self::unavailable();
        }

        $countKeys = [
            'https_sites_total', 'http2_configured', 'http2_unknown',
            'http3_configured', 'http3_partial', 'http3_not_configured', 'http3_unknown',
        ];
        $counts = [];
        foreach ($countKeys as $key) {
            if ($values[$key] === '' || !ctype_digit($values[$key])) {
                return self::unavailable();
            }
            $counts[$key] = (int) $values[$key];
            if ($counts[$key] > self::MAX_SITES) {
                return self::unavailable();
            }
        }

        $total = $counts['https_sites_total'];
        if ($counts['http2_configured'] + $counts['http2_unknown'] > $total) {
            return self::unavailable();
        }
        if ($counts['http3_configured'] + $counts['http3_partial']
            + $counts['http3_not_configured'] + $counts['http3_unknown'] !== $total) {
            return self::unavailable();
        }

        return [
            'available' => true,
            'sample' => false,
            'checked_at' => $values['checked_at'],
            'gzip' => $values['gzip'],
            'brotli_module' => $values['brotli_module'],
            'brotli_config' => $values['brotli_config'],
            ...$counts,
            'http3_module' => $values['http3_module'],
            'udp_443' => $values['udp_443'],
            'external_reachability' => 'not_tested',
        ];
    }

    public static function unavailable(): array
    {
        return [
            'available' => false,
            'sample' => false,
            'checked_at' => '',
            'gzip' => 'unknown',
            'brotli_module' => 'unknown',
            'brotli_config' => 'unknown',
            'https_sites_total' => 0,
            'http2_configured' => 0,
            'http2_unknown' => 0,
            'http3_module' => 'unknown',
            'http3_configured' => 0,
            'http3_partial' => 0,
            'http3_not_configured' => 0,
            'http3_unknown' => 0,
            'udp_443' => 'unknown',
            'external_reachability' => 'not_tested',
        ];
    }

    public static function sample(): array
    {
        return [
            'available' => true,
            'sample' => true,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'gzip' => 'configured',
            'brotli_module' => 'not_detected',
            'brotli_config' => 'not_configured',
            'https_sites_total' => 3,
            'http2_configured' => 3,
            'http2_unknown' => 0,
            'http3_module' => 'not_detected',
            'http3_configured' => 0,
            'http3_partial' => 0,
            'http3_not_configured' => 3,
            'http3_unknown' => 0,
            'udp_443' => 'not_listening',
            'external_reachability' => 'not_tested',
        ];
    }

    private static function validTimestamp(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new \DateTimeZone('UTC')
        );
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }
}
