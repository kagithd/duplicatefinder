<?php
namespace OCA\DuplicateFinder\Db;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
/** Immutable revisions, unique retry keys and transactional compare-and-swap head. */
class PlanMapper {
    private IDBConnection $db;
    public function __construct(IDBConnection $db) {
        $this->db=$db;
    }
    private function row(string $table,array $criteria):?array {
        $q=$this->db->getQueryBuilder();
        $q->select('*')->from($table);
        foreach($criteria as $key=>$v) {
            $q->andWhere($q->expr()->eq($key,$q->createNamedParameter($v,is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR)));
        }
        $r=$q->setMaxResults(1)->executeQuery();
        try {
            $row=$r->fetch();
            return $row===false?null:$row;
        }
        finally {
            $r->closeCursor();
        }
    }
    public function retry(string $creator,string $key,string $digest):?array {
        $row=$this->row('df_plan_revisions',['creator'=>$creator,'request_key'=>$key]);
        if($row===null) {
            return null;
        }
        if(!hash_equals($row['request_digest'],$digest)) {
            throw new EvidenceConflictException('Idempotency key reused with different content');
        }
        return json_decode($row['record_json'],true,512,JSON_THROW_ON_ERROR);
    }
    public function save(?string $planId,int $prev,string $key,string $digest,array $record):array {
        $creator=$record['creator'];
        $retry=$this->retry($creator,$key,$digest);
        if($retry!==null) {
            return $retry;
        }
        $this->db->beginTransaction();
        try {
            $q=$this->db->getQueryBuilder();
            if($planId===null) {
                $q->insert('df_review_plans')->values(['plan_id'=>$q->createNamedParameter($record['planId']),'head_revision'=>$q->createNamedParameter(1,IQueryBuilder::PARAM_INT),'created_at'=>$q->createNamedParameter($record['createdAt'],IQueryBuilder::PARAM_INT),'creator'=>$q->createNamedParameter($creator)])->executeStatement();
            }
            else {
                $q->update('df_review_plans')->set('head_revision',$q->createNamedParameter($prev+1,IQueryBuilder::PARAM_INT))->where($q->expr()->eq('plan_id',$q->createNamedParameter($planId)))->andWhere($q->expr()->eq('head_revision',$q->createNamedParameter($prev,IQueryBuilder::PARAM_INT)));
                if($q->executeStatement()!==1) {
                    throw new EvidenceConflictException('Plan predecessor changed or plan unavailable');
                }
            }
            $q=$this->db->getQueryBuilder();
            $values=[];
            foreach(['plan_id'=>$record['planId'],'revision'=>$record['revision'],'creator'=>$creator,'created_at'=>$record['createdAt'],'request_key'=>$key,'request_digest'=>$digest,'record_json'=>json_encode($record,JSON_THROW_ON_ERROR)] as $field=>$value) {
                $values[$field]=$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR);
            }
            $q->insert('df_plan_revisions')->values($values)->executeStatement();
            $this->db->commit();
            return $record;
        }
        catch(\Throwable $e) {
            $this->db->rollBack();
            $retry=$this->retry($creator,$key,$digest);
            if($retry!==null) {
                return $retry;
            }
            if($e instanceof EvidenceConflictException) {
                throw $e;
            }
            // Concurrent unique-key conflicts must not leak database details.
            if($e instanceof \OCP\DB\Exception && $e->getReason()===\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new EvidenceConflictException('Concurrent plan revision conflict',0,$e);
            }
            throw $e;
        }
    }
    public function revision(string $planId,int $revision):?array {
        $r=$this->row('df_plan_revisions',['plan_id'=>$planId,'revision'=>$revision]);
        return $r===null?null:json_decode($r['record_json'],true,512,JSON_THROW_ON_ERROR);
    }
    public function listing(int $cursor,int $limit):array {
        $q=$this->db->getQueryBuilder();
        $q->select('id','plan_id','head_revision','created_at','creator')->from('df_review_plans')->where($q->expr()->gt('id',$q->createNamedParameter($cursor,IQueryBuilder::PARAM_INT)))->orderBy('id','ASC')->setMaxResults($limit);
        $r=$q->executeQuery();
        try {
            return array_map(static fn($v)=>['id'=>(int)$v['id'],'planId'=>$v['plan_id'],'revision'=>(int)$v['head_revision'],'createdAt'=>(int)$v['created_at'],'creator'=>$v['creator'],'state'=>'decision_draft','executable'=>false],$r->fetchAll());
        }
        finally {
            $r->closeCursor();
        }
    }
}
