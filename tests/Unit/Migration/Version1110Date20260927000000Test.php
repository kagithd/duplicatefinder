<?php
namespace OCA\DuplicateFinder\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCA\DuplicateFinder\Migration\Version1110Date20260927000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1110Date20260927000000Test extends TestCase
{
    public function testAddsSearchColumnsWithoutReplacingHistoryAndIsRepeatable(): void
    {
        $schema=$this->schema();
        $table=$schema->createTable('df_review_evidence');
        $table->addColumn('id','bigint');
        $table->addColumn('record_json','text',['length'=>16777215]);
        $migration=new Version1110Date20260927000000();
        $output=$this->createMock(IOutput::class);
        $this->assertSame($schema,$migration->changeSchema($output,static fn()=>$schema,[]));
        $this->assertFalse($table->getColumn('search_status')->getNotnull());
        $this->assertSame(16,$table->getColumn('search_status')->getLength());
        $this->assertFalse($table->getColumn('search_format')->getNotnull());
        $this->assertSame(128,$table->getColumn('search_format')->getLength());
        $this->assertSame(['search_status','id'],$table->getIndex('df_evidence_status_id')->getColumns());
        $this->assertSame(['search_format','id'],$table->getIndex('df_evidence_format_id')->getColumns());
        $this->assertSame(16777215,$table->getColumn('record_json')->getLength());
        $before=serialize($table);
        $this->assertNull($migration->changeSchema($output,static fn()=>$schema,[]));
        $this->assertSame($before,serialize($table));
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
