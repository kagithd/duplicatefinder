<?php
namespace OCA\DuplicateFinder\Service;
use OCA\DuplicateFinder\Db\EvidenceMapper;

/** Read-only search; historical labels never assert current integrity. */
class EvidenceSearchService {
    public function __construct(private EvidenceMapper $mapper, private EvidenceService $evidence) {}
    public function search(int $cursor=0, int $limit=25, string $status='', string $format=''): array {
        if ($cursor<0 || $limit<1 || $limit>100 || strlen($format)>128 || strpos($format,"\0")!==false ||
            !in_array($status,['','passed','corrupt','unsupported','inaccessible','limit','stale','error','invalid'],true)) {
            throw new \InvalidArgumentException('Invalid evidence search');
        }
        $rows=$this->mapper->latestSearch($cursor,$limit+1,$status,$format);
        $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$items=[];
        foreach ($rows as $row) {
            $finding=null;$issue=null;
            if ($row['search_status']==='invalid') $issue='invalid_report';
            else {
                try {$finding=$this->evidence->getEvidence((int)$row['id'],(int)$row['app_ref']);}
                catch (\JsonException|\RuntimeException|\TypeError $e) {$issue='evidence_unavailable';}
                if ($finding===null && $issue===null) $issue='evidence_unavailable';
            }
            $items[]=['id'=>(int)$row['id'],'appRef'=>(int)$row['app_ref'],'createdAt'=>(int)$row['created_at'],
                'historicalStatus'=>$row['search_status'],'historicalFormat'=>$row['search_format'],
                'evidence'=>$finding,'issue'=>$issue];
        }
        return ['items'=>$items,'nextCursor'=>$more?(int)end($rows)['id']:null,
            'metadataComplete'=>!$this->mapper->hasUnprojectedEvidence(),'scope'=>'historical_reports_only'];
    }
}