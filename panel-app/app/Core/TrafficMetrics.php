<?php
declare(strict_types=1);

namespace Core;

final class TrafficMetrics
{
    private const READ_LIMIT_BYTES = 4_194_304;
    private const HIT_STATES = ['HIT', 'STALE', 'UPDATING', 'REVALIDATED'];
    private const MISS_STATES = ['MISS', 'EXPIRED'];

    public static function validDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,}$/D', $domain) === 1;
    }

    public static function collectorStatus(
        ?int $lastCollectedAt,
        int $now,
        int $unreadableLogs = 0,
        int $dataErrors = 0
    ): string
    {
        if ($lastCollectedAt === null) return 'collecting';
        if ($unreadableLogs > 0 || $dataErrors > 0
            || $lastCollectedAt > $now + 300 || $now - $lastCollectedAt > 180) {
            return 'unavailable';
        }
        return 'ready';
    }

    public static function summarize(array $values): array
    {
        $requests = max(0, (int) ($values['requests'] ?? 0));
        $hits = max(0, (int) ($values['cache_hits'] ?? 0));
        $misses = max(0, (int) ($values['cache_misses'] ?? 0));
        $bypass = max(0, (int) ($values['cache_bypass'] ?? 0));
        $eligible = $hits + $misses;
        return [
            'requests' => $requests,
            'cache_hits' => $hits,
            'cache_misses' => $misses,
            'cache_bypass' => $bypass,
            'cache_bytes' => max(0, (int) ($values['cache_bytes'] ?? 0)),
            'eligible' => $eligible,
            'hit_ratio' => $eligible > 0 ? round($hits / $eligible * 100, 1) : null,
        ];
    }

    public static function zeroFill(array $rows, int $startMinute, int $endMinute): array
    {
        if ($endMinute < $startMinute || intdiv($endMinute - $startMinute, 60) > 20_000) {
            return [];
        }

        $indexed = [];
        foreach ($rows as $row) {
            $minute = (int) ($row['minute'] ?? -1);
            if ($minute < $startMinute || $minute > $endMinute || $minute % 60 !== 0) continue;
            if (!isset($indexed[$minute])) {
                $indexed[$minute] = [
                    'requests' => 0, 'cache_hits' => 0, 'cache_misses' => 0,
                    'cache_bypass' => 0, 'cache_bytes' => 0,
                ];
            }
            foreach (array_keys($indexed[$minute]) as $key) {
                $indexed[$minute][$key] += max(0, (int) ($row[$key] ?? 0));
            }
        }

        $filled = [];
        for ($minute = $startMinute; $minute <= $endMinute; $minute += 60) {
            $filled[$minute] = $indexed[$minute] ?? [
                'requests' => 0, 'cache_hits' => 0, 'cache_misses' => 0,
                'cache_bypass' => 0, 'cache_bytes' => 0,
            ];
        }
        return $filled;
    }

    public static function parseLine(string $line): ?array
    {
        if (strlen($line) > 65_536) return null;
        if (preg_match(
            '/\[(?<time>\d{2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\]\s"[^"]*"\s\d{3}\s(?<bytes>\d+|-)\s.*\scache:(?<cache>[A-Za-z-]+)\s*$/',
            $line,
            $match
        ) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!d/M/Y:H:i:s O', $match['time']);
        if ($date === false || $date->format('d/M/Y:H:i:s O') !== $match['time']) {
            return null;
        }

        $bytes = 0;
        if ($match['bytes'] !== '-') {
            $rawBytes = $match['bytes'];
            $max = (string) PHP_INT_MAX;
            if (strlen($rawBytes) > strlen($max)
                || (strlen($rawBytes) === strlen($max) && strcmp($rawBytes, $max) > 0)) {
                return null;
            }
            $bytes = (int) $rawBytes;
        }

        $status = strtoupper($match['cache']);
        $hit = in_array($status, self::HIT_STATES, true) ? 1 : 0;
        $miss = in_array($status, self::MISS_STATES, true) ? 1 : 0;
        $bypass = $status === 'BYPASS' ? 1 : 0;
        $timestamp = $date->getTimestamp();

        return [
            'timestamp' => $timestamp,
            'minute' => intdiv($timestamp, 60) * 60,
            'requests' => 1,
            'cache_hits' => $hit,
            'cache_misses' => $miss,
            'cache_bypass' => $bypass,
            'cache_bytes' => $hit === 1 ? $bytes : 0,
        ];
    }

    public static function readIncrement(string $path, ?array $cursor): array
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (!is_array($stat) || !is_file($path) || !is_readable($path)) {
            return ['records' => [], 'cursor' => $cursor, 'bootstrapped' => false, 'gap' => false, 'error' => true];
        }

        $inode = (int) $stat['ino'];
        $size = (int) $stat['size'];
        if (!self::validCursor($cursor)) {
            return [
                'records' => [],
                'cursor' => ['inode' => $inode, 'offset' => $size],
                'bootstrapped' => true,
                'gap' => false,
                'error' => false,
            ];
        }

        $records = [];
        $gap = false;
        $error = false;
        if ((int) $cursor['inode'] !== $inode) {
            $rotated = $path . '.1';
            clearstatcache(true, $rotated);
            $oldStat = @stat($rotated);
            if (is_array($oldStat) && (int) $oldStat['ino'] === (int) $cursor['inode']) {
                $old = self::readCompleteRecords($rotated, (int) $cursor['offset']);
                $records = $old['records'];
                $error = $old['error'];
                if (!$old['at_eof']) {
                    return [
                        'records' => $records,
                        'cursor' => ['inode' => (int) $cursor['inode'], 'offset' => $old['offset']],
                        'bootstrapped' => false,
                        'gap' => false,
                        'error' => $error,
                    ];
                }
                $gap = $old['offset'] < (int) $oldStat['size'];
            } else {
                $gap = true;
            }
            $active = self::readCompleteRecords($path, 0);
            return [
                'records' => array_merge($records, $active['records']),
                'cursor' => ['inode' => $inode, 'offset' => $active['offset']],
                'bootstrapped' => false,
                'gap' => $gap,
                'error' => $error || $active['error'],
            ];
        }

        $offset = (int) $cursor['offset'];
        if ($size < $offset) {
            $offset = 0;
            $gap = true;
        }
        $active = self::readCompleteRecords($path, $offset);
        return [
            'records' => $active['records'],
            'cursor' => ['inode' => $inode, 'offset' => $active['offset']],
            'bootstrapped' => false,
            'gap' => $gap,
            'error' => $active['error'],
        ];
    }

    private static function validCursor(?array $cursor): bool
    {
        return is_array($cursor)
            && isset($cursor['inode'], $cursor['offset'])
            && is_numeric($cursor['inode'])
            && is_numeric($cursor['offset'])
            && (int) $cursor['inode'] >= 0
            && (int) $cursor['offset'] >= 0;
    }

    private static function readCompleteRecords(string $path, int $offset): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false || fseek($handle, $offset) !== 0) {
            if (is_resource($handle)) fclose($handle);
            return ['records' => [], 'offset' => $offset, 'at_eof' => false, 'error' => true];
        }

        $stat = fstat($handle);
        $data = stream_get_contents($handle, self::READ_LIMIT_BYTES);
        fclose($handle);
        if (!is_string($data) || $data === '') {
            return [
                'records' => [],
                'offset' => $offset,
                'at_eof' => is_array($stat) && $offset >= (int) $stat['size'],
                'error' => !is_string($data),
            ];
        }

        $lastNewline = strrpos($data, "\n");
        if ($lastNewline === false) {
            return [
                'records' => [],
                'offset' => $offset,
                'at_eof' => is_array($stat) && $offset + strlen($data) >= (int) $stat['size'],
                'error' => strlen($data) >= self::READ_LIMIT_BYTES,
            ];
        }

        $complete = substr($data, 0, $lastNewline + 1);
        $records = [];
        foreach (explode("\n", $complete) as $record) {
            $record = rtrim($record, "\r");
            if ($record !== '') $records[] = $record;
        }
        return [
            'records' => $records,
            'offset' => $offset + $lastNewline + 1,
            'at_eof' => is_array($stat) && $offset + strlen($data) >= (int) $stat['size'],
            'error' => false,
        ];
    }
}
