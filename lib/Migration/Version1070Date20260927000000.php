<?php

declare(strict_types=1);
namespace OCA\DuplicateFinder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1070Date20260927000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('df_review_evidence')) return null;
        $table = $schema->createTable('df_review_evidence');
        $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('app_ref', 'bigint', ['notnull' => true]);
        $table->addColumn('created_at', 'bigint', ['notnull' => true]);
        // The service bounds records to 128 KiB; plain MySQL TEXT is too small.
        $table->addColumn('record_json', 'text', ['notnull' => true, 'length' => 16777215]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['app_ref', 'id'], 'df_evidence_ref_id');
        // Deliberately no foreign key/cascade to the mutable candidate index.
        return $schema;
    }
}
