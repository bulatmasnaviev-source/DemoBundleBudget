<?php

namespace KimaiPlugin\DemoBundle\Repository;

use Doctrine\DBAL\Connection;

final class TodoListStorage
{
    private const TABLE = 'kimai2_demo_todo_list';
    private const DATE_COLUMNS = [
        'task_date',
        'created_at',
        'implementation_started_at',
        'completion_started_at',
        'completed_at',
        'cancelled_at',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(array $data): int
    {
        $this->connection->insert(self::TABLE, [
            'stage' => (string) ($data['stage'] ?? ''),
            'block' => (string) ($data['block'] ?? ''),
            'project_name' => (string) ($data['project_name'] ?? ''),
            'task_name' => (string) ($data['task_name'] ?? ''),
            'task_status' => (string) ($data['task_status'] ?? ''),
            'next_step' => (string) ($data['next_step'] ?? ''),
            'responsible' => (string) ($data['responsible'] ?? ''),
            'task_date' => (string) ($data['task_date'] ?? ''),
            'created_at' => (string) ($data['created_at'] ?? ''),
            'implementation_started_at' => $data['implementation_started_at'] ?? null,
            'completion_started_at' => $data['completion_started_at'] ?? null,
            'completed_at' => $data['completed_at'] ?? null,
            'cancelled_at' => $data['cancelled_at'] ?? null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateById(int $id, array $data): void
    {
        $this->connection->update(self::TABLE, [
            'block' => (string) ($data['block'] ?? ''),
            'project_name' => (string) ($data['project_name'] ?? ''),
            'task_name' => (string) ($data['task_name'] ?? ''),
            'task_status' => (string) ($data['task_status'] ?? ''),
            'next_step' => (string) ($data['next_step'] ?? ''),
            'responsible' => (string) ($data['responsible'] ?? ''),
            'task_date' => (string) ($data['task_date'] ?? ''),
        ], ['id' => $id]);
    }

    public function updateStageById(int $id, string $stage): void
    {
        $updates = ['stage' => $stage];
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        if ($stage === 'Инициация') {
            $updates['cancelled_at'] = null;
        } elseif ($stage === 'Реализация') {
            $updates['implementation_started_at'] = $today;
        } elseif ($stage === 'Завершение') {
            $updates['completion_started_at'] = $today;
        } elseif ($stage === 'Завершено') {
            $updates['completed_at'] = $today;
        } elseif ($stage === 'Отменено') {
            $updates['cancelled_at'] = $today;
        }

        $types = [];
        foreach (array_keys($updates) as $column) {
            if (\in_array($column, self::DATE_COLUMNS, true)) {
                $types[$column] = $updates[$column] === null ? \PDO::PARAM_NULL : \Doctrine\DBAL\ParameterType::STRING;
            }
        }

        $this->connection->update(self::TABLE, $updates, ['id' => $id], $types);
    }

    public function loadBuckets(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, block, stage, project_name, task_name, task_status, next_step, responsible, task_date
             FROM ' . self::TABLE . '
             ORDER BY id DESC'
        );

        $result = [
            'projects' => [],
            'research' => [],
            'completed' => [],
            'cancelled' => [],
        ];

        foreach ($rows as $row) {
            $normalized = [
                'id' => (int) ($row['id'] ?? 0),
                'block' => (string) ($row['block'] ?? ''),
                'stage' => (string) ($row['stage'] ?? ''),
                'project' => (string) ($row['project_name'] ?? ''),
                'task' => (string) ($row['task_name'] ?? ''),
                'taskStatus' => (string) ($row['task_status'] ?? ''),
                'nextStep' => (string) ($row['next_step'] ?? ''),
                'responsible' => (string) ($row['responsible'] ?? ''),
                'date' => (string) ($row['task_date'] ?? ''),
            ];

            if ($normalized['block'] === 'Проекты' && \in_array($normalized['stage'], ['Инициация', 'Реализация', 'Завершение'], true)) {
                $result['projects'][] = $normalized;
                continue;
            }

            if (\in_array($normalized['block'], ['Исследования', 'PR', 'G&A'], true)) {
                $result['research'][] = $normalized;
                continue;
            }

            if ($normalized['block'] === 'Проекты' && $normalized['stage'] === 'Завершено') {
                $result['completed'][] = $normalized;
                continue;
            }

            if ($normalized['block'] === 'Проекты' && $normalized['stage'] === 'Отменено') {
                $result['cancelled'][] = $normalized;
            }
        }

        return $result;
    }
}
