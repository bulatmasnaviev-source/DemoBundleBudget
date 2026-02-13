<?php

namespace KimaiPlugin\DemoBundle\Repository;

use Doctrine\DBAL\Connection;

final class BudgetPlanStorage
{
    private const PREFIX = 'budget_plan_';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function loadByProjectId(int $projectId): ?array
    {
        $name = self::PREFIX . $projectId;
        $value = $this->connection->fetchOne('SELECT value FROM kimai2_demo WHERE name = :name', ['name' => $name]);

        if (!\is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : null;
    }

    public function saveByProjectId(int $projectId, string $status, array $rows): void
    {
        $name = self::PREFIX . $projectId;
        $payload = json_encode([
            'status' => $status,
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR);

        $id = $this->connection->fetchOne('SELECT id FROM kimai2_demo WHERE name = :name', ['name' => $name]);

        if ($id === false || $id === null) {
            $this->connection->insert('kimai2_demo', [
                'name' => $name,
                'value' => $payload,
            ]);

            return;
        }

        $this->connection->update('kimai2_demo', ['value' => $payload], ['id' => (int) $id]);
    }
}
