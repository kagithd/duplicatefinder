<?php
namespace OCA\DuplicateFinder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1130Date20260928000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if ($schema->hasTable('df_detail_artifacts')) return null;
        $table = $schema->createTable('df_detail_artifacts');
        $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('app_ref', 'bigint', ['notnull' => true]);
        $table->addColumn('evidence_id', 'bigint', ['notnull' => true]);
        $table->addColumn('created_at', 'bigint', ['notnull' => true]);
        $table->addColumn('record_json', 'text', ['notnull' => true, 'length' => 16777215]);
        $table->addColumn('selection_hash', 'string', ['notnull' => true, 'length' => 64]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['app_ref', 'evidence_id', 'selection_hash'], 'df_detail_selection');
        return $schema;
    }
}
