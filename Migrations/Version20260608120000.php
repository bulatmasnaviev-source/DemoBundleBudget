<?php

namespace DemoBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260608120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the todo list table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_demo_todo_list')) {
            return;
        }

        $table = $schema->createTable('kimai2_demo_todo_list');
        $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('stage', 'string', ['length' => 100, 'notnull' => true]);
        $table->addColumn('block', 'string', ['length' => 100, 'notnull' => true]);
        $table->addColumn('project_name', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('task_name', 'text', ['notnull' => true]);
        $table->addColumn('task_status', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('next_step', 'text', ['notnull' => true]);
        $table->addColumn('responsible', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('task_date', 'date', ['notnull' => true]);
        $table->addColumn('created_at', 'date', ['notnull' => true]);
        $table->addColumn('implementation_started_at', 'date', ['notnull' => false]);
        $table->addColumn('completion_started_at', 'date', ['notnull' => false]);
        $table->addColumn('completed_at', 'date', ['notnull' => false]);
        $table->addColumn('cancelled_at', 'date', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['block', 'stage'], 'idx_kimai2_demo_todo_block_stage');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_demo_todo_list')) {
            $schema->dropTable('kimai2_demo_todo_list');
        }
    }
}
