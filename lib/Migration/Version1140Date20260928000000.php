<?php
namespace OCA\DuplicateFinder\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1140Date20260928000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
        $schema=$schemaClosure();
        if ($schema->hasTable('df_content_evidence')) return null;
        $table=$schema->createTable('df_content_evidence');
        $table->addColumn('id','bigint',['autoincrement'=>true,'notnull'=>true]);
        $table->addColumn('app_ref','bigint',['notnull'=>true]);
        $table->addColumn('created_at','bigint',['notnull'=>true]);
        $table->addColumn('record_json','text',['length'=>65535,'notnull'=>true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['app_ref','id'],'df_content_history');
        return $schema;
    }
}
