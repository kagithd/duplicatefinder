<?php
namespace OCA\DuplicateFinder\Service;

use OCA\DuplicateFinder\Db\ContentEvidenceMapper;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;

/** Trusted CLI/in-process imports only. Readability is not format validity or action approval. */
class ContentEvidenceService {
    private ContentEvidenceMapper $mapper;
    private EvidenceSnapshotService $snapshots;
    public function __construct(ContentEvidenceMapper $mapper, EvidenceSnapshotService $snapshots) {
        $this->mapper=$mapper; $this->snapshots=$snapshots;
    }
    public function record(int $appRef, array $report): int {
        $this->validate($appRef,$report);
        try { $current=$this->snapshots->snapshot($appRef); }
        catch (\RuntimeException $e) { throw new EvidenceConflictException('Current reference unavailable',0,$e); }
        if ($current !== $report['after']) throw new EvidenceConflictException('Content binding changed');
        $record=['schemaVersion'=>1,'kind'=>'content','report'=>$report];
        $json=json_encode($record,JSON_THROW_ON_ERROR);
        if (strlen($json)>32768) throw new \InvalidArgumentException('Content record too large');
        return $this->mapper->append($appRef,time(),$json);
    }
    public function getEvidence(int $id,int $appRef): ?array {
        if ($id<1 || $id>=PHP_INT_MAX || $appRef<1) return null;
        $item=$this->history($appRef,$id+1,1)['items'][0]??null;
        return $item!==null && $item['id']===$id ? $item : null;
    }
    public function history(int $appRef, int $cursor=0, int $limit=25): array {
        if ($appRef<1 || $cursor<0 || $limit<1 || $limit>100) throw new \InvalidArgumentException('Invalid content page');
        $rows=$this->mapper->history($appRef,$cursor,$limit+1);
        $more=count($rows)>$limit; $rows=array_slice($rows,0,$limit);
        $current=null;
        if ($rows!==[]) {
            try { $current=$this->snapshots->snapshot($appRef); }
            catch (\RuntimeException $e) { /* Historical observations survive missing files. */ }
        }
        $items=[];
        foreach ($rows as $row) {
            $record=json_decode($row['record_json'],true,512,JSON_THROW_ON_ERROR);
            $status='unverifiable'; $reason='native_revision_not_rechecked';
            if ($current===null) $reason='nextcloud_revision_unavailable';
            elseif ($current!==$record['report']['after']) { $status='stale'; $reason='nextcloud_revision_changed'; }
            $items[]=['id'=>(int)$row['id'],'appRef'=>$appRef,'createdAt'=>(int)$row['created_at'],
                'record'=>$record,'usability'=>$status,'reason'=>$reason,'actionEligible'=>false];
        }
        return ['items'=>$items,'nextCursor'=>$more?(int)end($rows)['id']:null];
    }
    private function validate(int $appRef,array $r): void {
        $fields=['appRef','status','reason','algorithm','digest','formatStatus','actionEligible','scope','nativeFreshness',
            'checker','before','after','revision_before','revision_after','limits'];
        if ($appRef<1 || count($r)!==count($fields) || array_diff($fields,array_keys($r))
            || strlen(json_encode($r,JSON_THROW_ON_ERROR))>32768
            || $r['appRef']!==$appRef || $r['status']!=='read' || $r['reason']!=='content_read'
            || $r['algorithm']!=='sha256' || !is_string($r['digest']) || !preg_match('/\A[a-f0-9]{64}\z/',$r['digest'])
            || $r['formatStatus']!=='not_checked' || $r['actionEligible']!==false
            || $r['scope']!=='original_bytes_observed' || $r['nativeFreshness']!=='observed_not_locked_after') {
            throw new \InvalidArgumentException('Invalid content observation');
        }
        if (!is_array($r['checker']) || count($r['checker'])!==3) throw new \InvalidArgumentException('Invalid checker');
        foreach (['id','version','ruleVersion'] as $field) {
            if (!is_string($r['checker'][$field]??null) || $r['checker'][$field]==='' || strlen($r['checker'][$field])>128) {
                throw new \InvalidArgumentException('Explicit checker identity required');
            }
        }
        if (!is_array($r['before']) || $r['before']!==$r['after'] || ($r['before']['appRef']??null)!==$appRef
            || !is_int($r['before']['size']??null) || $r['before']['size']<0
            || !is_string($r['before']['revisionToken']??null) || !preg_match('/\A[a-f0-9]{64}\z/',$r['before']['revisionToken'])) {
            throw new \InvalidArgumentException('Invalid content binding');
        }
        foreach (['revision_before','revision_after'] as $key) {
            $n=$r[$key];
            if (!is_array($n) || count($n)!==6 || ($n['regular']??null)!==true) throw new \InvalidArgumentException('Regular native revision required');
            foreach (['dev','ino','size','mtime_ns','ctime_ns'] as $field) {
                if (!is_string($n[$field]??null) || !preg_match('/\A(?:0|[1-9][0-9]{0,29})\z/',$n[$field])) {
                    throw new \InvalidArgumentException('Exact native revision required');
                }
            }
        }
        if ($r['revision_before']!==$r['revision_after'] || $r['revision_before']['size']!==(string)$r['before']['size']
            || !is_array($r['limits']) || count($r['limits'])!==1 || !is_int($r['limits']['max_bytes']??null)
            || $r['limits']['max_bytes']<1 || $r['before']['size']>$r['limits']['max_bytes']) {
            throw new \InvalidArgumentException('Changed or unbounded content observation');
        }
    }
}
