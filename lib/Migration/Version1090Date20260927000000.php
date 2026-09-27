<?php
namespace OCA\DuplicateFinder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1090Date20260927000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if ($schema->hasTable('df_preview_artifacts')) return null;
        $table = $schema->createTable('df_preview_artifacts');
        $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('app_ref', 'bigint', ['notnull' => true]);
        $table->addColumn('evidence_id', 'bigint', ['notnull' => true]);
        $table->addColumn('created_at', 'bigint', ['notnull' => true]);
        $table->addColumn('record_json', 'text', ['notnull' => true, 'length' => 16777215]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['evidence_id'], 'df_preview_evidence');
        return $schema;
    }
}
