<?php
namespace OCA\DuplicateFinder\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1080Date20260927000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
        $s=$schemaClosure();
        $changed=false;
        if(!$s->hasTable('df_review_plans')) {
            $t=$s->createTable('df_review_plans');
            $t->addColumn('id','bigint',['autoincrement'=>true,'notnull'=>true]);
            $t->addColumn('plan_id','string',['length'=>32,'notnull'=>true]);
            $t->addColumn('head_revision','integer',['notnull'=>true]);
            $t->addColumn('created_at','bigint',['notnull'=>true]);
            $t->addColumn('creator','string',['length'=>64,'notnull'=>true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['plan_id'],'df_plan_identity');
            $changed=true;
        }
        if(!$s->hasTable('df_plan_revisions')) {
            $t=$s->createTable('df_plan_revisions');
            $t->addColumn('id','bigint',['autoincrement'=>true,'notnull'=>true]);
            $t->addColumn('plan_id','string',['length'=>32,'notnull'=>true]);
            $t->addColumn('revision','integer',['notnull'=>true]);
            $t->addColumn('creator','string',['length'=>64,'notnull'=>true]);
            $t->addColumn('created_at','bigint',['notnull'=>true]);
            $t->addColumn('request_key','string',['length'=>128,'notnull'=>true]);
            $t->addColumn('request_digest','string',['length'=>64,'notnull'=>true]);
            $t->addColumn('record_json','text',['length'=>16777215,'notnull'=>true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['plan_id','revision'],'df_plan_revision');
            $t->addUniqueIndex(['creator','request_key'],'df_plan_retry');
            $changed=true;
        }
        return $changed?$s:null;
    }
}
