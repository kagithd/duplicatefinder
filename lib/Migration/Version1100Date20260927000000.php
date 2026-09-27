<?php
namespace OCA\DuplicateFinder\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1100Date20260927000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
        $s=$schemaClosure();if($s->hasTable('df_check_jobs')) return null;
        $t=$s->createTable('df_check_jobs');
        $t->addColumn('id','bigint',['autoincrement'=>true,'notnull'=>true]);
        foreach(['job_id'=>32,'creator'=>64,'request_key'=>128,'request_digest'=>64,'state'=>16,'lease_token'=>64] as $name=>$length) $t->addColumn($name,'string',['length'=>$length,'notnull'=>true]);
        foreach(['request_json','record_json'] as $name) $t->addColumn($name,'text',['length'=>16777215,'notnull'=>true]);
        foreach(['lease_until','row_version'] as $name) $t->addColumn($name,'bigint',['notnull'=>true]);
        $t->setPrimaryKey(['id']);$t->addUniqueIndex(['job_id'],'df_check_job_id');$t->addUniqueIndex(['creator','request_key'],'df_check_retry');$t->addIndex(['state','lease_until'],'df_check_pending');
        return $s;
    }
}
