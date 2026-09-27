<?php

namespace OCA\DuplicateFinder\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCA\DuplicateFinder\Migration\Version1060Date20260927000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1060Date20260927000000Test extends TestCase
{
    public function testAddsCoveringIndexWithoutChangingExistingIndexesOrColumns(): void
    {
        $schema = $this->schema();
        $table = $this->fileInfoTable($schema);
        $oldIndexes = $table->getIndexes();
        $oldColumns = serialize($table->getColumns());
        $oldIndexDefinitions = array_map(static fn ($index) => serialize($index), $oldIndexes);

        $result = (new Version1060Date20260927000000())->changeSchema(
            $this->createMock(IOutput::class), static fn () => $schema, []
        );

        $this->assertSame($schema, $result);
        $this->assertTrue($table->hasIndex('df_finfo_ignored_hash'));
        $index = $table->getIndex('df_finfo_ignored_hash');
        $this->assertSame(['ignored', 'file_hash'], $index->getColumns());
        $this->assertFalse($index->isUnique());
        $this->assertCount(count($oldIndexes) + 1, $table->getIndexes());
        foreach ($oldIndexes as $name => $oldIndex) {
            $this->assertSame($oldIndexDefinitions[$name], serialize($table->getIndex($name)));
        }
        $this->assertSame($oldColumns, serialize($table->getColumns()));
    }

    public function testAlreadyMigratedSchemaIsUnchangedOnRepeatedRuns(): void
    {
        $schema = $this->schema();
        $table = $this->fileInfoTable($schema);
        $table->addIndex(['ignored', 'file_hash'], 'df_finfo_ignored_hash');
        $before = serialize($table->getIndexes());
        $migration = new Version1060Date20260927000000();
        $output = $this->createMock(IOutput::class);

        $this->assertNull($migration->changeSchema($output, static fn () => $schema, []));
        $this->assertNull($migration->changeSchema($output, static fn () => $schema, []));
        $this->assertSame($before, serialize($table->getIndexes()));
    }

    public function testMissingLegacyTableIsLeftUntouched(): void
    {
        $schema = $this->schema();
        $this->assertNull((new Version1060Date20260927000000())->changeSchema(
            $this->createMock(IOutput::class), static fn () => $schema, []
        ));
        $this->assertSame([], $schema->getTables());
    }

    private function fileInfoTable(ISchemaWrapper $schema): Table
    {
        $table = $schema->createTable('duplicatefinder_finfo');
        $table->addColumn('id', 'integer');
        $table->addColumn('ignored', 'boolean', ['notnull' => false, 'default' => false]);
        $table->addColumn('file_hash', 'string', ['length' => 64, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['ignored'], 'duplicatefinder_i_idx');
        $table->addIndex(['file_hash'], 'duplicatefinder_hashes_idx');
        return $table;
    }

    private function schema(): ISchemaWrapper
    {
        // The adapter delegates every operation to real Doctrine schema objects.
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
