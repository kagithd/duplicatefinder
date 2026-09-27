<?php
namespace OCA\DuplicateFinder\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCA\DuplicateFinder\Migration\Version1090Date20260927000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1090Date20260927000000Test extends TestCase
{
    public function testImmutableRevisionAndRetryConstraintsAndAdditiveRepeat(): void
    {
        $schema = $this->schema();
        $migration = new Version1090Date20260927000000();
        $output = $this->createMock(IOutput::class);
        $this->assertSame($schema, $migration->changeSchema($output, static fn () => $schema, []));
        $table = $schema->getTable('df_preview_artifacts');
        $this->assertTrue($table->getIndex('df_preview_evidence')->isUnique());
        $this->assertSame(['evidence_id'], $table->getIndex('df_preview_evidence')->getColumns());
        $this->assertSame([], $table->getForeignKeys());
        $this->assertGreaterThanOrEqual(131072, $table->getColumn('record_json')->getLength());
        $before = serialize($table);
        $this->assertNull($migration->changeSchema($output, static fn () => $schema, []));
        $this->assertSame($before, serialize($table));
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
