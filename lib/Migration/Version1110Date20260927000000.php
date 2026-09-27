<?php
namespace OCA\DuplicateFinder\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add searchable historical labels; NULL explicitly means not yet projected. */
class Version1110Date20260927000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if (!$schema->hasTable('df_review_evidence')) return null;
        $table=$schema->getTable('df_review_evidence');
        $changed=false;
        foreach (['search_status'=>16,'search_format'=>128] as $name=>$length) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name,'string',['length'=>$length,'notnull'=>false]);
                $changed=true;
            }
        }
        foreach (['df_evidence_status_id'=>'search_status','df_evidence_format_id'=>'search_format'] as $name=>$column) {
            if (!$table->hasIndex($name)) {
                $table->addIndex([$column,'id'],$name);
                $changed=true;
            }
        }
        return $changed ? $schema : null;
    }
}