<?php
declare(strict_types=1);

namespace Core;

final class CloudInstanceMetadata
{
    private const KEYS = [
        'schema', 'status', 'provider', 'instance_id', 'region', 'source', 'checked_at',
    ];

    private const PROVIDERS = [
        'aws'          => 'Amazon EC2',
        'azure'        => 'Microsoft Azure',
        'gcp'          => 'Google Cloud',
        'digitalocean' => 'DigitalOcean',
        'hetzner'      => 'Hetzner Cloud',
        'oracle'       => 'Oracle Cloud',
        'vultr'        => 'Vultr',
        'linode'       => 'Akamai Cloud / Linode',
        'alibaba'      => 'Alibaba Cloud',
        'openstack'    => 'OpenStack',
        'scaleway'     => 'Scaleway',
        'upcloud'      => 'UpCloud',
        'exoscale'     => 'Exoscale',
    ];

    public static function parse(string $output): array
    {
        $values = [];
        $lines = preg_split('/\r?\n/', trim($output));
        if (!is_array($lines) || $lines === [] || $lines === ['']) {
            return self::unknown();
        }

        foreach ($lines as $line) {
            if ($line === '' || !str_contains($line, '=')) {
                return self::unknown();
            }
            [$key, $value] = explode('=', $line, 2);
            if (!in_array($key, self::KEYS, true) || array_key_exists($key, $values)) {
                return self::unknown();
            }
            $values[$key] = $value;
        }
        if (count($values) !== count(self::KEYS)) {
            return self::unknown();
        }
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $values)) {
                return self::unknown();
            }
        }

        $status = $values['status'];
        $providerKey = $values['provider'];
        $instanceId = $values['instance_id'];
        $region = $values['region'];
        $source = $values['source'];
        $missingPlaceholders = ['null', 'none', 'unknown'];
        if ($values['schema'] !== '1'
            || !in_array($status, ['detected', 'partial', 'unknown'], true)
            || !in_array($source, ['cloud-init', 'metadata', 'mixed', 'none'], true)
            || !self::validTimestamp($values['checked_at'])
            || in_array(strtolower($instanceId), $missingPlaceholders, true)
            || in_array(strtolower($region), $missingPlaceholders, true)
            || ($instanceId !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $instanceId) !== 1)
            || ($region !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $region) !== 1)) {
            return self::unknown();
        }

        $providerKnown = array_key_exists($providerKey, self::PROVIDERS);
        $detected = $providerKnown && $instanceId !== '' && $region !== '' && $source !== 'none';
        $partial = $providerKnown && ($instanceId === '' || $region === '') && $source !== 'none';
        $unknown = $providerKey === '' && $instanceId === '' && $region === '' && $source === 'none';
        if (($status === 'detected' && !$detected)
            || ($status === 'partial' && !$partial)
            || ($status === 'unknown' && !$unknown)) {
            return self::unknown();
        }

        return [
            'available' => true,
            'status' => $status,
            'provider_key' => $providerKey,
            'provider' => $providerKnown ? self::PROVIDERS[$providerKey] : null,
            'instance_id' => $instanceId !== '' ? $instanceId : null,
            'region' => $region !== '' ? $region : null,
            'source' => $source,
            'checked_at' => $values['checked_at'],
        ];
    }

    public static function unknown(): array
    {
        return [
            'available' => false,
            'status' => 'unknown',
            'provider_key' => '',
            'provider' => null,
            'instance_id' => null,
            'region' => null,
            'source' => 'none',
            'checked_at' => '',
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
