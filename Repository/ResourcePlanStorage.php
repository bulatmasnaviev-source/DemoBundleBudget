<?php

namespace KimaiPlugin\DemoBundle\Repository;

use Doctrine\DBAL\Connection;

final class ResourcePlanStorage
{
    private const PREFIX = 'resource_plan_';

    private string $fallbackStorage;

    public function __construct(private readonly Connection $connection, string $dataDirectory)
    {
        $this->fallbackStorage = rtrim($dataDirectory, '/') . '/resource-plans.json';
    }

    public function loadByIntervalId(string $intervalId): ?array
    {
        $name = self::PREFIX . $intervalId;

        try {
            $value = $this->connection->fetchOne('SELECT value FROM kimai2_demo WHERE name = :name', ['name' => $name]);

            if (!\is_string($value) || $value === '') {
                return null;
            }

            $decoded = json_decode($value, true);

            return \is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return $this->loadFromFallback($name);
        }
    }

    public function saveByIntervalId(string $intervalId, string $status, array $cells): void
    {
        $name = self::PREFIX . $intervalId;
        $payload = json_encode([
            'status' => $status,
            'cells' => $cells,
        ], JSON_THROW_ON_ERROR);

        try {
            $id = $this->connection->fetchOne('SELECT id FROM kimai2_demo WHERE name = :name', ['name' => $name]);

            if ($id === false || $id === null) {
                $this->connection->insert('kimai2_demo', [
                    'name' => $name,
                    'value' => $payload,
                ]);

                return;
            }

            $this->connection->update('kimai2_demo', ['value' => $payload], ['id' => (int) $id]);
        } catch (\Throwable) {
            $this->saveToFallback($name, [
                'status' => $status,
                'cells' => $cells,
            ]);
        }
    }

    private function loadFromFallback(string $name): ?array
    {
        if (!file_exists($this->fallbackStorage)) {
            return null;
        }

        $raw = file_get_contents($this->fallbackStorage);

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded) || !isset($decoded[$name]) || !\is_array($decoded[$name])) {
            return null;
        }

        return $decoded[$name];
    }

    private function saveToFallback(string $name, array $data): void
    {
        $vars = [];

        if (file_exists($this->fallbackStorage)) {
            $raw = file_get_contents($this->fallbackStorage);
            if (\is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (\is_array($decoded)) {
                    $vars = $decoded;
                }
            }
        }

        $vars[$name] = $data;

        file_put_contents($this->fallbackStorage, json_encode($vars, JSON_THROW_ON_ERROR));
    }
}
