<?php
namespace OCA\DuplicateFinder\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCA\DuplicateFinder\Migration\Version1130Date20260928000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1130Date20260928000000Test extends TestCase
{
    public function testImmutableRevisionAndRetryConstraintsAndAdditiveRepeat(): void
    {
        $schema = $this->schema();
        $migration = new Version1130Date20260928000000();
        $output = $this->createMock(IOutput::class);
        $this->assertSame($schema, $migration->changeSchema($output, static fn () => $schema, []));
        $table = $schema->getTable('df_detail_artifacts');
        $this->assertTrue($table->getIndex('df_detail_selection')->isUnique());
        $this->assertSame(['app_ref', 'evidence_id', 'selection_hash'], $table->getIndex('df_detail_selection')->getColumns());
        $this->assertSame([], $table->getForeignKeys());
        $this->assertGreaterThanOrEqual(3145728, $table->getColumn('record_json')->getLength());
        $before = serialize($table);
        $this->assertNull($migration->changeSchema($output, static fn () => $schema, []));
        $this->assertSame($before, serialize($table));
    }
    private function schema(): ISchemaWrapper
    {
        $schema = new Schema();
        $wrapper = $this->createMock(ISchemaWrapper::class);
        $wrapper->method('hasTable')->willReturnCallback(static fn($name) => $schema->hasTable($name));
        $adapt = static fn(Table $table) => interface_exists('OCP\\DB\\Schema\\ITable') ? new \OC\DB\Schema\Table($table) : $table;
        $wrapper->method('createTable')->willReturnCallback(static fn($name) => $adapt($schema->createTable($name)));
        $wrapper->method('getTable')->willReturnCallback(static fn($name) => $adapt($schema->getTable($name)));
        return $wrapper;
    }
}
