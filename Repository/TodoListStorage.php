<?php

namespace KimaiPlugin\DemoBundle\Repository;

use Doctrine\DBAL\Connection;

final class TodoListStorage
{
    private const TABLE = 'kimai2_demo_todo_list';

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
