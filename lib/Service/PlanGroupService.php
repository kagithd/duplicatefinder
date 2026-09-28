<?php
namespace OCA\DuplicateFinder\Service;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;

/** Capture explicitly selected membership pages, never infer effective access. */
class PlanGroupService {
    public function __construct(private ReviewGroupService $groups) {}
    public function capture(int $appRef, array $selected, array $observed): array {
        if (!array_is_list($selected) || count($selected)>20) throw new \InvalidArgumentException('At most 20 membership pages');
        $seen=[];
        foreach ($selected as $entry) {
            if (!is_array($entry) || count($entry)!==2 || array_diff(array_keys($entry),['query','expected']) ||
                !is_array($entry['query']??null) || count($entry['query'])!==4 ||
                array_diff(array_keys($entry['query']),['depth','shareOffset','shareId','offset']) ||
                !is_array($entry['expected']??null)) throw new \InvalidArgumentException('Invalid membership selection');
            $q=$entry['query'];
            if (!is_int($q['depth']) || $q['depth']<0 || $q['depth']>32 ||
                !is_int($q['shareOffset']) || $q['shareOffset']<0 || $q['shareOffset']>1000000 ||
                !is_int($q['offset']) || $q['offset']<0 || $q['offset']>1000000 ||
                !is_string($q['shareId']) || !preg_match('/\A[0-9]{1,20}\z/',$q['shareId'])) {
                throw new \InvalidArgumentException('Invalid membership selector');
            }
            $key=$this->canonical($q);
            if (isset($seen[$key])) throw new \InvalidArgumentException('Repeated membership page');
            $seen[$key]=true;
            $expected=$entry['expected'];
            if (($expected['appRef']??null)!==$appRef || !is_array($expected['observed']??null) ||
                $this->canonical($expected['observed'])!==$this->canonical($observed)) {
                throw new EvidenceConflictException('Membership file binding changed');
            }
            if (!is_int($expected['observedAt']??null) || $expected['observedAt']<1) {
                throw new \InvalidArgumentException('Missing membership observation time');
            }
        }
        $pages=[];
        foreach ($selected as $entry) {
            $q=$entry['query'];
            $actual=$this->groups->page($appRef,$q['depth'],$q['shareOffset'],$q['shareId'],$q['offset'],25);
            $expectedComparable=$entry['expected'];$actualComparable=$actual;
            unset($expectedComparable['observedAt'],$actualComparable['observedAt'],
                $expectedComparable['sharePage']['observedAt'],$actualComparable['sharePage']['observedAt']);
            if ($this->canonical($expectedComparable)!==$this->canonical($actualComparable)) {
                throw new EvidenceConflictException('Selected membership page changed');
            }
            $pages[]=['query'=>$q,'selectedObservedAt'=>$entry['expected']['observedAt'],'page'=>$actual];
        }
        return ['complete'=>false,'coverage'=>$pages===[]?'not_observed':'selected_membership_pages_only','pages'=>$pages,
            'limitations'=>['membership_is_not_effective_access','unselected_members_not_checked',
                'membership_pages_not_atomic','no_execution_authorization']];
    }
    private function canonical(array $value): string {
        $sort=function(&$data) use (&$sort): void {
            if (!is_array($data)) return;
            if (!array_is_list($data)) ksort($data);
            foreach ($data as &$child) $sort($child);
        };
        $sort($value);
        return json_encode($value,JSON_THROW_ON_ERROR);
    }
}
