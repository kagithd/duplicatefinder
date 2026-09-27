<?php
namespace OCA\DuplicateFinder\Service;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;

/** Revalidate user-selected sharing observations before immutable plan persistence. */
class PlanShareService {
    public function __construct(private ReviewShareService $shares) {}
    public function capture(int $appRef, array $selected, array $observed): array {
        if (!array_is_list($selected) || count($selected)>20) throw new \InvalidArgumentException('At most 20 sharing pages');
        $seen=[];
        foreach ($selected as $entry) {
            if (!is_array($entry) || count($entry)!==2 || array_diff(array_keys($entry),['query','expected']) ||
                !is_array($entry['query']??null) || count($entry['query'])!==3 || array_diff(array_keys($entry['query']),['depth','type','offset']) ||
                !is_array($entry['expected']??null)) throw new \InvalidArgumentException('Invalid sharing selection');
            $q=$entry['query'];
            if (!is_int($q['depth']) || $q['depth']<0 || $q['depth']>32 || !is_int($q['offset']) || $q['offset']<0 || $q['offset']>1000000 ||
                !is_int($q['type']) || !in_array($q['type'],[0,1,3],true)) throw new \InvalidArgumentException('Invalid sharing selector');
            $key=$q['depth'].':'.$q['type'].':'.$q['offset'];
            if (isset($seen[$key])) throw new \InvalidArgumentException('Repeated sharing page');
            $seen[$key]=true;
            $expected=$entry['expected'];
            if (($expected['appRef']??null)!==$appRef || !is_array($expected['observed']??null) ||
                $this->canonical($expected['observed'])!==$this->canonical($observed)) throw new EvidenceConflictException('Sharing file binding changed');
            if (!is_int($expected['observedAt']??null) || $expected['observedAt']<1) throw new \InvalidArgumentException('Missing observation time');
        }
        $pages=[];
        foreach ($selected as $entry) {
            $q=$entry['query'];
            $actual=$this->shares->page($appRef,$q['depth'],$q['type'],$q['offset'],25);
            $expectedComparable=$entry['expected']; $actualComparable=$actual;
            unset($expectedComparable['observedAt'],$actualComparable['observedAt']);
            if ($this->canonical($actualComparable)!==$this->canonical($expectedComparable)) throw new EvidenceConflictException('Selected sharing page changed');
            $pages[]=['query'=>$q,'selectedObservedAt'=>$entry['expected']['observedAt'],'page'=>$actual];
        }
        return ['complete'=>false,'coverage'=>$pages===[]?'not_observed':'selected_pages_only','pages'=>$pages,
            'limitations'=>['unselected_pages_and_providers_not_checked','share_pages_not_atomic','no_execution_authorization']];
    }
    private function canonical(array $value): string {
        $sort=function (&$data) use (&$sort): void {
            if (!is_array($data)) return;
            if (!array_is_list($data)) ksort($data);
            foreach ($data as &$child) $sort($child);
        };
        $sort($value);
        return json_encode($value,JSON_THROW_ON_ERROR);
    }
}
