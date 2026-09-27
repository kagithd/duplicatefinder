<?php
namespace OCA\DuplicateFinder\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCA\DuplicateFinder\Migration\Version1120Date20260928000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1120Date20260928000000Test extends TestCase
{
    public function testAddsCursorIndexesAndPreservesExistingIndex(): void
    {
        $schema=$this->schema();
        $table=$schema->createTable('duplicatefinder_finfo');
        foreach (['id'=>'bigint','ignored'=>'boolean','owner'=>'string','file_hash'=>'string'] as $name=>$type) $table->addColumn($name,$type);
        $table->addIndex(['ignored','file_hash'],'df_finfo_ignored_hash');
        $migration=new Version1120Date20260928000000();
        $output=$this->createMock(IOutput::class);
        $this->assertSame($schema,$migration->changeSchema($output,static fn()=>$schema,[]));
        $this->assertSame(['ignored','id'],$table->getIndex('df_finfo_ignored_id')->getColumns());
        $this->assertSame(['ignored','owner','id'],$table->getIndex('df_finfo_owner_id')->getColumns());
        $this->assertSame(['ignored','file_hash'],$table->getIndex('df_finfo_ignored_hash')->getColumns());
        $before=serialize($table);
        $this->assertNull($migration->changeSchema($output,static fn()=>$schema,[]));
        $this->assertSame($before,serialize($table));
    }
    public function testMissingTableIsNoOp(): void
    {
        $schema=$this->schema();
        $this->assertNull((new Version1120Date20260928000000())->changeSchema($this->createMock(IOutput::class),static fn()=>$schema,[]));
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
