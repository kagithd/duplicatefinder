<?php
namespace OCA\DuplicateFinder\Service;

use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use OCP\{IGroupManager,IUser};

/** Explicit membership observation, never a claim of effective file access. */
class ReviewGroupService {
    public function __construct(private ReviewShareService $shares, private IGroupManager $groups) {}

    public function page(int $appRef, int $depth, int $shareOffset, string $shareId, int $offset=0, int $limit=25): array {
        if ($appRef<1 || $depth<0 || $depth>32 || $shareOffset<0 || $shareOffset>1000000 ||
            !preg_match('/\A[0-9]{1,20}\z/',$shareId) || $offset<0 || $offset>1000000 || $limit<1 || $limit>100) {
            throw new \InvalidArgumentException('Invalid group membership selector');
        }
        // Resolve through the bounded, file-bound group-share page. No arbitrary group lookup.
        $page=$this->shares->page($appRef,$depth,1,$shareOffset,25);
        $matches=array_values(array_filter($page['items'],static fn($item)=>
            (string)($item['id']??'')===$shareId && ($item['type']??null)===1));
        if (count($matches)!==1 || !is_string($matches[0]['recipient']??null) || $matches[0]['recipient']==='') {
            throw new EvidenceConflictException('Group share is no longer in the selected page');
        }
        $share=$matches[0];
        $group=$this->groups->get($share['recipient']);
        if ($group===null || $group->getGID()!==$share['recipient']) throw new EvidenceConflictException('Shared group unavailable');
        $backends=$group->getBackendNames();
        $result=['appRef'=>$appRef,'observed'=>$page['observed'],'observedAt'=>time(),
            'sharePage'=>$page,'shareId'=>$shareId,'groupId'=>$share['recipient'],'backends'=>$backends,
            'scope'=>'group_membership_only','complete'=>false,'members'=>[],'nextOffset'=>null,
            'status'=>'backend_not_qualified',
            'limitations'=>['membership_is_not_effective_access','unselected_members_not_checked',
                'membership_pages_not_atomic','other_access_paths_not_checked','no_execution_authorization']];
        // Nextcloud applies limits separately to each backend. Only the tested local single-backend case is qualified.
        if ($backends!==['Database']) return $result;
        $rows=$group->searchUsers('',$limit+1,$offset);
        if (count($rows)>$limit+1) throw new \RuntimeException('Group backend ignored the page bound');
        $members=[]; $seen=[];
        foreach (array_slice(array_values($rows),0,$limit) as $user) {
            if (!$user instanceof IUser || $user->getUID()==='' || isset($seen[$user->getUID()])) {
                throw new \RuntimeException('Invalid group member response');
            }
            $seen[$user->getUID()]=true;
            $members[]=['uid'=>$user->getUID(),'enabled'=>$user->isEnabled(),'accessStatus'=>'not_resolved'];
        }
        $after=$this->shares->page($appRef,$depth,1,$shareOffset,25);
        $beforeComparable=$page; unset($beforeComparable['observedAt'],$after['observedAt']);
        if ($beforeComparable!==$after || $group->getBackendNames()!==$backends) {
            throw new EvidenceConflictException('Group sharing context changed during observation');
        }
        $result['status']='observed';
        $result['members']=$members;
        $result['nextOffset']=count($rows)>$limit ? $offset+$limit : null;
        return $result;
    }
}
