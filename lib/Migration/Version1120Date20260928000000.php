<?php
declare(strict_types=1);
namespace OCA\DuplicateFinder\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Support bounded ID pagination without sorting the full candidate index. */
class Version1120Date20260928000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if (!$schema->hasTable('duplicatefinder_finfo')) return null;
        $table = $schema->getTable('duplicatefinder_finfo');
        $changed = false;
        foreach (['df_finfo_ignored_id' => ['ignored', 'id'], 'df_finfo_owner_id' => ['ignored', 'owner', 'id']] as $name => $columns) {
            if (!$table->hasIndex($name)) {
                $table->addIndex($columns, $name);
                $changed = true;
            }
        }
        return $changed ? $schema : null;
    }
}
