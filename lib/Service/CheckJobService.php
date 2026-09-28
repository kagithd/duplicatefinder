<?php
namespace OCA\DuplicateFinder\Service;

use OCA\DuplicateFinder\Db\CheckJobMapper;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use OCP\AppFramework\Utility\ITimeFactory;

/** Metadata-only queue. Trusted local workers perform original reads; HTTP cannot import results. */
class CheckJobService {
    private CheckJobMapper $mapper;
    private ReviewService $review;
    private EvidenceSnapshotService $snapshots;
    private EvidenceService $evidence;
    private PreviewArtifactService $previews;
    private ITimeFactory $clock;
    private DetailArtifactService $details;
    private ContentEvidenceService $content;
    public function __construct(CheckJobMapper $mapper,ReviewService $review,EvidenceSnapshotService $snapshots,EvidenceService $evidence,PreviewArtifactService $previews,ITimeFactory $clock,DetailArtifactService $details,ContentEvidenceService $content) {
        $this->mapper=$mapper;$this->review=$review;$this->snapshots=$snapshots;$this->evidence=$evidence;$this->previews=$previews;$this->clock=$clock;$this->details=$details;$this->content=$content;
    }
    public function create(array $payload,string $creator): array {
        if(strlen(json_encode($payload,JSON_THROW_ON_ERROR))>262144||array_diff(array_keys($payload),['idempotencyKey','members','preview','kind'])) throw new \InvalidArgumentException('Invalid bounded check request');
        $kind=$payload['kind']??'image';
        if (!in_array($kind,['image','content'],true) || ($kind==='content' && ($payload['preview']??null)!==false)) throw new \InvalidArgumentException('Invalid check kind or mixed preview');
        $key=$payload['idempotencyKey']??null;
        if(!is_string($key)||!preg_match('/\A[A-Za-z0-9_-]{8,128}\z/',$key)||!is_bool($payload['preview']??null)||$creator===''||strlen($creator)>64) throw new \InvalidArgumentException('Invalid check identity or preview flag');
        $members=$payload['members']??null;
        if(!is_array($members)||!array_is_list($members)||count($members)<1||count($members)>20) throw new \InvalidArgumentException('Select 1 to 20 references');
        $seen=[];
        foreach($members as $member) {
            if(!is_array($member)||array_diff(array_keys($member),['appRef','expected','detail'])||!is_int($member['appRef']??null)||$member['appRef']<1||isset($seen[$member['appRef']])||!is_array($member['expected']??null)) throw new \InvalidArgumentException('Invalid or repeated check reference');
            $seen[$member['appRef']]=true;
            if(array_key_exists('detail',$member)) {
                if($kind==='content') throw new \InvalidArgumentException('Content cannot request decoder detail');
                $this->validateDetail($member['detail']);
            }
        }
        $digest=hash('sha256',$this->canonical($payload));
        $retry=$this->mapper->retry($creator,$key,$digest);
        if($retry!==null) return $this->publicRecord($this->expire($retry));
        $now=$this->clock->getTime();
        $record=['jobId'=>bin2hex(random_bytes(16)),'state'=>'queued','creator'=>$creator,'createdAt'=>$now,'updatedAt'=>$now,'preview'=>$payload['preview'],'kind'=>$kind,'items'=>[],'completedCount'=>0,'total'=>count($members),'limitations'=>['native_revision_not_rechecked'],'rowVersion'=>0,'leaseToken'=>'','leaseUntil'=>0];
        foreach($members as $member) {
            $ref=$member['appRef'];$current=$this->review->reference($ref);
            if($current===null||$this->canonical($current)!==$this->canonical($member['expected'])) throw new EvidenceConflictException('Selected reference changed');
            try { $snapshot=$this->snapshots->snapshot($ref); }
            catch(\RuntimeException $e) { throw new EvidenceConflictException('Selected reference metadata unavailable',0,$e); }
            foreach($snapshot as $field=>$value) {
                if(in_array($field,['appRef','revisionToken'],true)) continue;
                if(!array_key_exists($field,$current)||$current[$field]!==$value) throw new EvidenceConflictException('Selected revision changed during queueing');
            }
            $item=['appRef'=>$ref,'snapshot'=>$snapshot,'status'=>'pending'];
            if(array_key_exists('detail',$member)) $item['detail']=$member['detail'];
            $record['items'][]=$item;
        }
        // Re-read the full observed reference after preparing all snapshots.
        foreach($members as $member) {
            $current=$this->review->reference($member['appRef']);
            if($current===null||$this->canonical($current)!==$this->canonical($member['expected'])) throw new EvidenceConflictException('Selection changed during queueing');
        }
        $request=['payload'=>$payload,'snapshots'=>array_column($record['items'],'snapshot')];
        if(strlen(json_encode($request,JSON_THROW_ON_ERROR))+strlen(json_encode($record,JSON_THROW_ON_ERROR))>900000) throw new \InvalidArgumentException('Saved check request too large');
        return $this->publicRecord($this->mapper->insert($key,$digest,$request,$record));
    }
    public function get(string $jobId): ?array {
        $this->id($jobId);$record=$this->mapper->find($jobId);return $record===null?null:$this->publicRecord($this->expire($record));
    }
    public function listing(int $cursor=0,int $limit=25): array {
        if($cursor<0||$limit<1||$limit>100) throw new \InvalidArgumentException('Invalid check page');
        $rows=$this->mapper->listing($cursor,$limit+1);$more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
        return ['items'=>array_map(fn($row)=>$this->publicRecord($this->expire($row)),$rows),'nextCursor'=>$more?(int)end($rows)['cursor']:null];
    }
    /** Cancellation fences results. An already executing read-only decoder/import may finish. */
    public function cancel(string $jobId): ?array {
        $this->id($jobId);
        for($attempt=0;$attempt<5;$attempt++) {
            $record=$this->mapper->find($jobId);if($record===null) return null;$record=$this->expire($record);
            if(!in_array($record['state'],['queued','running'],true)) return $this->publicRecord($record);
            $after=$record;$after['state']='cancelled';$after['leaseToken']='';$after['leaseUntil']=0;
            if($this->save($record,$after)) return $this->publicRecord($after);
        }
        throw new EvidenceConflictException('Check job changed concurrently');
    }
    /** Internal only. No route exposes claims, heartbeats, tokens or result imports. */
    public function claim(): ?array {
        foreach($this->mapper->candidates($this->clock->getTime()) as $record) {
            $record=$this->expire($record);if($record['state']!=='queued') continue;
            $after=$record;$after['state']='running';$after['leaseToken']=bin2hex(random_bytes(32));$after['leaseUntil']=$this->clock->getTime()+300;
            if($this->save($record,$after)) return ['jobId'=>$after['jobId'],'leaseToken'=>$after['leaseToken'],'preview'=>$after['preview'],'kind'=>$after['kind']??'image','items'=>$after['items']];
        }
        return null;
    }
    public function heartbeat(string $jobId,string $leaseToken): bool {
        $this->id($jobId);$record=$this->mapper->find($jobId);if($record===null) return false;$record=$this->expire($record);
        if(!$this->active($record,$leaseToken)) return false;
        $after=$record;$after['leaseUntil']=$this->clock->getTime()+300;
        return $this->save($record,$after);
    }
    public function completeItem(string $jobId,string $leaseToken,int $appRef,array $result): array {
        $this->id($jobId);$record=$this->mapper->find($jobId);if($record!==null) $record=$this->expire($record);
        if($record===null||!$this->active($record,$leaseToken)) throw new EvidenceConflictException('Check lease is no longer active');
        $position=null;
        foreach($record['items'] as $index=>$item) { if($item['status']==='pending') { $position=$index;break; } }
        if($position===null||$record['items'][$position]['appRef']!==$appRef) throw new EvidenceConflictException('Result does not match the next selected reference');
        if (($record['kind']??'image')==='content') $this->validateContentResult($result,$appRef,$record['items'][$position]['snapshot']);
        else $this->validateResult($result,$appRef,$record['items'][$position]['snapshot'],$record['preview'],$record['items'][$position]['detail']??null);
        $after=$record;$after['items'][$position]=array_merge($after['items'][$position],$result);$after['completedCount']++;
        if($after['completedCount']===$after['total']) { $after['state']='completed';$after['leaseToken']='';$after['leaseUntil']=0; }
        if(!$this->save($record,$after)) throw new EvidenceConflictException('Check lease or job changed concurrently');
        return $this->publicRecord($after);
    }
    private function validateContentResult(array $result,int $ref,array $snapshot): void {
        if (array_diff(array_keys($result),['status','reason','contentEvidenceId'])
            || !in_array($result['status']??null,['read','unsupported','inaccessible','limit','stale','error'],true)) {
            throw new \InvalidArgumentException('Invalid content check result');
        }
        if (array_key_exists('reason',$result) && (!is_string($result['reason']) || strlen($result['reason'])>1024)) {
            throw new \InvalidArgumentException('Invalid content reason');
        }
        if ($result['status']==='read' && !isset($result['contentEvidenceId'])) throw new \InvalidArgumentException('Read requires content evidence');
        if (array_key_exists('contentEvidenceId',$result)) {
            $id=$result['contentEvidenceId'];
            if (!is_int($id) || $id<1 || $id>=PHP_INT_MAX || !in_array($result['status'],['read','stale'],true)) throw new \InvalidArgumentException('Invalid content evidence identity');
            $e=$this->content->getEvidence($id,$ref);
            $r=$e['record']['report']??[];
            if ($e===null || ($e['id']??null)!==$id || ($e['appRef']??null)!==$ref || ($r['status']??null)!=='read'
                || $this->canonical($r['before']??[])!==$this->canonical($snapshot)
                || $this->canonical($r['after']??[])!==$this->canonical($snapshot)) {
                throw new \InvalidArgumentException('Content evidence does not match selected revision');
            }
        }
    }
    private function validateResult(array $result,int $ref,array $snapshot,bool $preview,?array $detail): void {
        if(array_diff(array_keys($result),['status','reason','evidenceId','previewId','detailId','detailStatus'])||!in_array($result['status']??null,['passed','corrupt','unsupported','inaccessible','limit','stale','error'],true)) throw new \InvalidArgumentException('Invalid check result');
        if(array_key_exists('reason',$result)&&(!is_string($result['reason'])||strlen($result['reason'])>1024)) throw new \InvalidArgumentException('Invalid bounded check reason');
        foreach(['evidenceId','previewId','detailId'] as $field) if(array_key_exists($field,$result)&&(!is_int($result[$field])||$result[$field]<1||$result[$field]>=PHP_INT_MAX)) throw new \InvalidArgumentException('Invalid result artifact identity');
        if($result['status']==='passed'&&!isset($result['evidenceId'])) throw new \InvalidArgumentException('Passed requires persisted evidence');
        if(isset($result['evidenceId'])) {
            $evidence=$this->evidence->getEvidence($result['evidenceId'],$ref);
            if($evidence===null||($evidence['appRef']??null)!==$ref||($result['status']!=='stale'&&($evidence['record']['report']['status']??null)!==$result['status'])||$this->canonical($evidence['record']['before']??[])!==$this->canonical($snapshot)||$this->canonical($evidence['record']['after']??[])!==$this->canonical($snapshot)) throw new \InvalidArgumentException('Evidence does not match selected reference, revision or result');
        }
        if(array_key_exists('detailStatus',$result) || isset($result['detailId'])) {
            if($detail===null || !in_array($result['detailStatus']??null,['available','unavailable','error'],true)
                || (($result['detailStatus']==='available')!==isset($result['detailId']))) {
                throw new \InvalidArgumentException('Detail status must match the requested artifact');
            }
        }
        if($detail!==null && $result['status']==='passed' && !isset($result['detailStatus'])) {
            throw new \InvalidArgumentException('A detail request requires an explicit artifact outcome');
        }
        if(isset($result['detailId'])) {
            if(!isset($result['evidenceId'])) throw new \InvalidArgumentException('Detail requires persisted evidence');
            $artifact=$this->details->getDetail($ref,$result['evidenceId'],$result['detailId']);
            $descriptor=$artifact['record']['descriptor']??[];
            $region=$detail;unset($region['frameIndex']);
            if($artifact===null || ($artifact['id']??null)!==$result['detailId'] || ($artifact['appRef']??null)!==$ref
                || ($artifact['evidenceId']??null)!==$result['evidenceId']
                || $this->canonical($artifact['record']['boundSnapshot']??[])!==$this->canonical($snapshot)
                || ($descriptor['scope']??null)!=='original_selected_frame_region'
                || ($descriptor['frameIndex']??null)!==$detail['frameIndex']
                || $this->canonical($descriptor['region']??[])!==$this->canonical($region)) {
                throw new \InvalidArgumentException('Detail does not match selected evidence, frame or region');
            }
        }
        if(isset($result['previewId'])) {
            if(!$preview||!isset($result['evidenceId'])) throw new \InvalidArgumentException('Preview requires requested preview and evidence');
            $artifact=$this->previews->getPreview($ref,$result['evidenceId']);
            if($artifact===null||($artifact['id']??null)!==$result['previewId']||($artifact['appRef']??null)!==$ref||($artifact['evidenceId']??null)!==$result['evidenceId']||$this->canonical($artifact['record']['boundSnapshot']??[])!==$this->canonical($snapshot)) throw new \InvalidArgumentException('Preview does not match selected evidence');
        }
    }
    private function validateDetail($detail): void {
        if(!is_array($detail) || count($detail)!==5 || array_diff(['frameIndex','x','y','width','height'],array_keys($detail))) {
            throw new \InvalidArgumentException('Invalid detail selection');
        }
        foreach($detail as $field=>$value) {
            $dimension=in_array($field,['width','height'],true);
            if(!is_int($value) || $value<($dimension?1:0) || $value>($dimension?512:2147483647)) {
                throw new \InvalidArgumentException('Invalid bounded frame or region');
            }
        }
    }
    private function active(array $record,string $token): bool {
        return $record['state']==='running'&&preg_match('/\A[a-f0-9]{64}\z/',$token)&&hash_equals($record['leaseToken'],$token)&&$record['leaseUntil']>$this->clock->getTime();
    }
    private function expire(array $record): array {
        for($attempt=0;$attempt<5;$attempt++) {
            if($record['state']!=='running'||$record['leaseUntil']>$this->clock->getTime()) return $record;
            $after=$record;$after['state']='interrupted';$after['leaseToken']='';$after['leaseUntil']=0;
            if($this->save($record,$after)) return $after;
            $record=$this->mapper->find($record['jobId']);
            if($record===null) throw new EvidenceConflictException('Check job unavailable');
        }
        throw new EvidenceConflictException('Check job changed concurrently');
    }
    private function save(array $before,array &$after): bool {
        $now=$this->clock->getTime();$after['updatedAt']=$now;
        if(strlen(json_encode($after,JSON_THROW_ON_ERROR))>524288) throw new \InvalidArgumentException('Check state too large');
        return $this->mapper->compareAndSwap($before,$after,$now);
    }
    private function publicRecord(array $record): array {
        return array_intersect_key($record,array_flip(['jobId','state','creator','createdAt','updatedAt','preview','kind','items','completedCount','total','limitations']));
    }
    private function id(string $id): void { if(!preg_match('/\A[a-f0-9]{32}\z/',$id)) throw new \InvalidArgumentException('Invalid check job identity'); }
    private function canonical(array $value): string {
        $sort=function(&$v) use (&$sort) { if(!is_array($v)) return;if(!array_is_list($v)) ksort($v,SORT_STRING);foreach($v as &$item) $sort($item); };
        $sort($value);return json_encode($value,JSON_THROW_ON_ERROR);
    }
}
