<?php
namespace OCA\DuplicateFinder\Db;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;

/** Queue persistence only. Immutable request and mutable state are separate columns. */
class CheckJobMapper {
    private IDBConnection $db;
    public function __construct(IDBConnection $db) { $this->db=$db; }
    private function decode(array $row): array {
        $record=json_decode($row['record_json'],true,512,JSON_THROW_ON_ERROR);
        $record['rowVersion']=(int)$row['row_version'];
        $record['cursor']=(int)$row['id'];
        return $record;
    }
    private function row(array $criteria): ?array {
        $q=$this->db->getQueryBuilder();$q->select('*')->from('df_check_jobs');
        foreach($criteria as $key=>$value) $q->andWhere($q->expr()->eq($key,$q->createNamedParameter($value)));
        $r=$q->setMaxResults(1)->executeQuery();
        try { $row=$r->fetch();return $row===false?null:$row; } finally { $r->closeCursor(); }
    }
    public function retry(string $creator,string $key,string $digest): ?array {
        $row=$this->row(['creator'=>$creator,'request_key'=>$key]);
        if($row===null) return null;
        if(!hash_equals($row['request_digest'],$digest)) throw new EvidenceConflictException('Idempotency key reused with different content');
        return $this->decode($row);
    }
    public function insert(string $key,string $digest,array $request,array $record): array {
        $retry=$this->retry($record['creator'],$key,$digest);if($retry!==null) return $retry;
        $q=$this->db->getQueryBuilder();$values=[];
        foreach(['job_id'=>$record['jobId'],'creator'=>$record['creator'],'request_key'=>$key,'request_digest'=>$digest,'request_json'=>json_encode($request,JSON_THROW_ON_ERROR),'record_json'=>json_encode($record,JSON_THROW_ON_ERROR),'state'=>$record['state'],'lease_token'=>'','lease_until'=>0,'row_version'=>0] as $field=>$value) {
            $values[$field]=$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR);
        }
        try { $q->insert('df_check_jobs')->values($values)->executeStatement(); }
        catch(\OCP\DB\Exception $e) {
            if($e->getReason()!==\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) throw $e;
            $retry=$this->retry($record['creator'],$key,$digest);if($retry!==null) return $retry;
            throw new EvidenceConflictException('Concurrent queue request conflict',0,$e);
        }
        return $record;
    }
    public function find(string $jobId): ?array { $row=$this->row(['job_id'=>$jobId]);return $row===null?null:$this->decode($row); }
    public function listing(int $cursor,int $limit): array {
        $q=$this->db->getQueryBuilder();$q->select('id','row_version','record_json')->from('df_check_jobs')->where($q->expr()->gt('id',$q->createNamedParameter($cursor,IQueryBuilder::PARAM_INT)))->orderBy('id','ASC')->setMaxResults($limit);
        $r=$q->executeQuery();try { return array_map(fn($row)=>$this->decode($row),$r->fetchAll()); } finally { $r->closeCursor(); }
    }
    public function candidates(int $now): array {
        $q=$this->db->getQueryBuilder();$q->select('id','row_version','record_json')->from('df_check_jobs')->where($q->expr()->orX($q->expr()->eq('state',$q->createNamedParameter('queued')),$q->expr()->andX($q->expr()->eq('state',$q->createNamedParameter('running')),$q->expr()->lte('lease_until',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT)))))->orderBy('state','ASC')->addOrderBy('id','ASC')->setMaxResults(100);
        $r=$q->executeQuery();try { return array_map(fn($row)=>$this->decode($row),$r->fetchAll()); } finally { $r->closeCursor(); }
    }
    /** Every update compares version, state and lease; stale workers cannot overwrite cancellation. */
    public function compareAndSwap(array $before,array $after,int $now): bool {
        unset($after['cursor']);$after['rowVersion']=$before['rowVersion']+1;
        $q=$this->db->getQueryBuilder();$q->update('df_check_jobs');
        foreach(['record_json'=>json_encode($after,JSON_THROW_ON_ERROR),'state'=>$after['state'],'row_version'=>$after['rowVersion'],'lease_token'=>$after['leaseToken']??'','lease_until'=>$after['leaseUntil']??0] as $key=>$value) $q->set($key,$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));
        foreach(['job_id'=>$before['jobId'],'row_version'=>$before['rowVersion'],'state'=>$before['state'],'lease_token'=>$before['leaseToken']??'','lease_until'=>$before['leaseUntil']??0] as $key=>$value) $q->andWhere($q->expr()->eq($key,$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR)));
        if($before['state']==='running') {
            $comparison=$after['state']==='interrupted'?'lte':'gt';
            $q->andWhere($q->expr()->$comparison('lease_until',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT)));
        }
        return $q->executeStatement()===1;
    }
}
