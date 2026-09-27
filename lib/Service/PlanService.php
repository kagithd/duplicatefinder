<?php
namespace OCA\DuplicateFinder\Service;
use OCA\DuplicateFinder\Db\PlanMapper;
use OCA\DuplicateFinder\Db\ReviewMapper;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
/** Decision persistence only: no approval, execution, index mutation or file access. */
class PlanService {
    private PlanMapper $mapper;
    private ReviewService $review;
    private ReviewMapper $index;
    private EvidenceService $evidence;
    public function __construct(PlanMapper $mapper,ReviewService $review,ReviewMapper $index,EvidenceService $evidence) {
        $this->mapper=$mapper;
        $this->review=$review;
        $this->index=$index;
        $this->evidence=$evidence;
    }
    public function create(array $payload,string $creator):array {
        return $this->write(null,0,$payload,$creator);
    }
    public function append(string $planId,array $payload,string $creator):array {
        $this->id($planId);
        $prev=$payload['expectedPredecessor']??null;
        if(!is_int($prev)||$prev<1) {
            throw new \InvalidArgumentException('Expected predecessor required');
        }
        return $this->write($planId,$prev,$payload,$creator);
    }
    private function write(?string $planId,int $prev,array $p,string $creator):array {
        if(strlen(json_encode($p,JSON_THROW_ON_ERROR))>262144) {
            throw new \InvalidArgumentException('Plan request too large');
        }
        $allowed=['hash','members','indexActions','note','idempotencyKey'];
        if($planId!==null) {
            $allowed[]='expectedPredecessor';
        }
        if(array_diff(array_keys($p),$allowed)) {
            throw new \InvalidArgumentException('Unknown plan fields');
        }
        if(!is_string($p['hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/',$p['hash'])) {
            throw new \InvalidArgumentException('SHA-256 required');
        }
        $key=$p['idempotencyKey']??null;
        if(!is_string($key)||!preg_match('/\A[A-Za-z0-9_-]{8,128}\z/',$key)) {
            throw new \InvalidArgumentException('Invalid idempotency key');
        }
        if(($p['indexActions']??null)!==[]) {
            throw new \InvalidArgumentException('Index actions are not supported');
        }
        $this->note($p['note']??null,4096);
        if(!is_array($p['members']??null)||!array_is_list($p['members'])||count($p['members'])<1||count($p['members'])>100) {
            throw new \InvalidArgumentException('Select 1 to 100 references');
        }
        $digest=hash('sha256',$this->canonical(['planId'=>$planId,'predecessor'=>$prev,'payload'=>$p]));
        $retry=$this->mapper->retry($creator,$key,$digest);
        if($retry!==null) {
            return $retry;
        }
        $record=['schemaVersion'=>1,'planId'=>$planId??bin2hex(random_bytes(16)),'revision'=>$prev+1,'creator'=>$creator,'createdAt'=>time(),'state'=>'decision_draft','executable'=>false,'hash'=>$p['hash'],'members'=>[],'indexActions'=>[],'note'=>$p['note'],'limitations'=>['indexed_hash_not_revalidated','native_revision_not_rechecked','no_execution_authorization']];
        $recordBytes = strlen(json_encode($record, JSON_THROW_ON_ERROR));
        $members=[];
        $seen=[];
        $physical=[];
        $keep=0;
        $remove=0;
        foreach($p['members'] as $m) {
            if(!is_array($m)||array_diff(array_keys($m),['appRef','action','expected','evidenceIds','manualAssessment','reason'])) {
                throw new \InvalidArgumentException('Invalid member fields');
            }
            $ref=$m['appRef']??null;
            $action=$m['action']??null;
            if(!is_int($ref)||$ref<1||isset($seen[$ref])||!in_array($action,['keep','remove','exclude'],true)) {
                throw new \InvalidArgumentException('Invalid or repeated reference/action');
            }
            $seen[$ref]=true;
            $this->note($m['reason']??null,2048);
            $manual=$m['manualAssessment']??null;
            if(!is_array($manual)||array_diff(array_keys($manual),['status','note'])||!in_array($manual['status']??null,['not_assessed','content_visible','problem'],true)) {
                throw new \InvalidArgumentException('Invalid manual assessment');
            }
            $this->note($manual['note']??null,2048);
            $current=$this->review->reference($ref);
            if($current===null||$this->index->candidateHash($ref)!==$p['hash']||!is_array($m['expected']??null)||$this->canonical($current)!==$this->canonical($m['expected'])) {
                throw new EvidenceConflictException('Reference, candidate hash or observed metadata changed');
            }
            if(($current['availability']??null)!=='available'&&$action!=='exclude') {
                throw new \InvalidArgumentException('Unavailable references may only be excluded');
            }
            if($action!=='exclude') {
                foreach(['storageId','nodeId','etag','size','mtime','owner','path'] as $field) {
                    if(!isset($current[$field])) {
                        throw new EvidenceConflictException('Incomplete observed identity');
                    }
                }
                $identity=$this->canonical([$current['storageId'],$current['nodeId']]);
                if(isset($physical[$identity])&&($physical[$identity]!==$action||$action==='remove')) {
                    throw new \InvalidArgumentException('Conflicting or repeated physical action');
                }
                $physical[$identity]=$action;
            }
            if($action==='keep') {
                $keep++;
            }
            if($action==='remove') {
                $remove++;
            }
            $ids=$m['evidenceIds']??null;
            if(!is_array($ids)||!array_is_list($ids)||count($ids)>20||count(array_unique($ids,SORT_REGULAR))!==count($ids)) {
                throw new \InvalidArgumentException('Invalid evidence selection');
            }
            $member = ['appRef' => $ref, 'action' => $action, 'observed' => $current,
                'candidateHash' => $p['hash'], 'evidence' => [],
                'manualAssessment' => $manual, 'reason' => $m['reason']];
            // Account for the member and array comma before loading any findings.
            $this->addRecordBytes($recordBytes,
                strlen(json_encode($member, JSON_THROW_ON_ERROR)) + ($members === [] ? 0 : 1));
            $findings=[];
            foreach($ids as $id) {
                if(!is_int($id)||$id<1) {
                    throw new \InvalidArgumentException('Invalid evidence ID');
                }
                $finding=$this->evidence->getEvidence($id,$ref);
                if($finding===null) {
                    throw new \InvalidArgumentException('Evidence does not belong to reference');
                }
                // Each finding replaces content inside the already counted []:
                // only subsequent findings need an additional separator byte.
                $this->addRecordBytes($recordBytes,
                    strlen(json_encode($finding, JSON_THROW_ON_ERROR)) + ($findings === [] ? 0 : 1));
                $findings[]=$finding;
            }
            $member['evidence'] = $findings;
            $members[] = $member;
        }
        // Re-read after evidence resolution; this remains metadata-only, not a lock.
        foreach ($members as $member) {
            $current = $this->review->reference($member['appRef']);
            if ($current === null || $this->index->candidateHash($member['appRef']) !== $p['hash'] || $this->canonical($current) !== $this->canonical($member['observed'])) {
                throw new EvidenceConflictException('Selection changed while preparing plan');
            }
        }
        if($remove>0&&$keep===0) {
            throw new \InvalidArgumentException('Removal decisions require a kept reference');
        }
        $record['members'] = $members;
        if(strlen(json_encode($record,JSON_THROW_ON_ERROR))>2097152) {
            throw new \InvalidArgumentException('Saved revision too large');
        }
        return $this->mapper->save($planId,$prev,$key,$digest,$record);
    }
    public function listing(int $cursor=0,int $limit=25):array {
        if($cursor<0||$limit<1||$limit>100) {
            throw new \InvalidArgumentException('Invalid plan page');
        }
        $rows=$this->mapper->listing($cursor,$limit+1);
        $more=count($rows)>$limit;
        $rows=array_slice($rows,0,$limit);
        return ['items'=>$rows,'nextCursor'=>$more?(int)end($rows)['id']:null];
    }
    public function revision(string $planId,int $revision):?array {
        $this->id($planId);
        if($revision<1) {
            throw new \InvalidArgumentException('Invalid revision');
        }
        return $this->mapper->revision($planId,$revision);
    }
    private function addRecordBytes(int &$total, int $additional): void
    {
        $total += $additional;
        if ($total > 2097152) {
            throw new \InvalidArgumentException('Saved revision too large');
        }
    }
    private function id(string $id):void {
        if(!preg_match('/\A[a-f0-9]{32}\z/',$id)) {
            throw new \InvalidArgumentException('Invalid plan ID');
        }
    }
    private function note($value,int $max):void {
        if(!is_string($value)||strlen($value)>$max) {
            throw new \InvalidArgumentException('Invalid or oversized note');
        }
    }
    private function canonical(array $a):string {
        $sort=function(&$v)use(&$sort) {
            if(!is_array($v)) {
                return;
            }
            if(!array_is_list($v)) {
                ksort($v,SORT_STRING);
            }
            foreach($v as &$item) {
                $sort($item);
            }
        }
        ;
        $sort($a);
        return json_encode($a,JSON_THROW_ON_ERROR);
    }
}
