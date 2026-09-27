<?php
namespace OCA\DuplicateFinder\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCA\DuplicateFinder\Migration\Version1080Date20260927000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1080Date20260927000000Test extends TestCase
{
    public function testImmutableRevisionAndRetryConstraintsAndAdditiveRepeat(): void
    {
        $schema = $this->schema();
        $migration = new Version1080Date20260927000000();
        $output = $this->createMock(IOutput::class);
        $this->assertSame($schema, $migration->changeSchema($output, static fn () => $schema, []));
        $plans = $schema->getTable('df_review_plans');
        $revisions = $schema->getTable('df_plan_revisions');
        $this->assertTrue($plans->getIndex('df_plan_identity')->isUnique());
        $this->assertSame(['plan_id'], $plans->getIndex('df_plan_identity')->getColumns());
        $this->assertTrue($revisions->getIndex('df_plan_revision')->isUnique());
        $this->assertSame(['plan_id', 'revision'], $revisions->getIndex('df_plan_revision')->getColumns());
        $this->assertTrue($revisions->getIndex('df_plan_retry')->isUnique());
        $this->assertSame(['creator', 'request_key'], $revisions->getIndex('df_plan_retry')->getColumns());
        $this->assertSame([], $revisions->getForeignKeys());
        $this->assertGreaterThanOrEqual(2097152, $revisions->getColumn('record_json')->getLength());
        $before = serialize([$plans, $revisions]);
        $this->assertNull($migration->changeSchema($output, static fn () => $schema, []));
        $this->assertSame($before, serialize([$plans, $revisions]));
    }
    private function schema(): ISchemaWrapper
    {
        return new class implements ISchemaWrapper {
            private Schema $schema;
            public function __construct() { $this->schema = new Schema(); }
            public function getTable($tableName): Table { return $this->schema->getTable($tableName); }
            public function hasTable($tableName): bool { return $this->schema->hasTable($tableName); }
            public function createTable($tableName): Table { return $this->schema->createTable($tableName); }
            public function dropTable($tableName) { return $this->schema->dropTable($tableName); }
            public function getTables(): array { return $this->schema->getTables(); }
            public function getTableNames(): array { return $this->schema->getTableNames(); }
            public function getTableNamesWithoutPrefix(): array { return $this->schema->getTableNames(); }
            public function getDatabasePlatform(): AbstractPlatform { return new SqlitePlatform(); }
        };
    }
}
