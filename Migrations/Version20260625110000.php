<?php

namespace DemoBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260625110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add substage and cancel reason columns to the todo list table';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_demo_todo_list')) {
            return;
        }

        $table = $schema->getTable('kimai2_demo_todo_list');

        if (!$table->hasColumn('substage')) {
            $table->addColumn('substage', 'string', ['length' => 255, 'notnull' => false, 'default' => null]);
        }

        if (!$table->hasColumn('cancel_reason')) {
            $table->addColumn('cancel_reason', 'text', ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_demo_todo_list')) {
            return;
        }

        $table = $schema->getTable('kimai2_demo_todo_list');

        if ($table->hasColumn('cancel_reason')) {
            $table->dropColumn('cancel_reason');
        }

        if ($table->hasColumn('substage')) {
            $table->dropColumn('substage');
        }
    }
}
