<?php

declare(strict_types=1);

namespace OCA\DuplicateFinder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Cover bounded global review queries without replacing existing indexes. */
class Version1060Date20260927000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('duplicatefinder_finfo')) {
            return null;
        }

        $table = $schema->getTable('duplicatefinder_finfo');
        if ($table->hasIndex('df_finfo_ignored_hash')) {
            return null;
        }

        $table->addIndex(['ignored', 'file_hash'], 'df_finfo_ignored_hash');
        return $schema;
    }
}
