<?php

namespace DemoBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260406120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the working plan table';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_demo_working')) {
            $table = $schema->createTable('kimai2_demo_working');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('name', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('value', 'text', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['name']);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_demo_working')) {
            $schema->dropTable('kimai2_demo_working');
        }
    }
}
