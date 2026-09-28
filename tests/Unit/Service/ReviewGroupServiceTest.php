<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;
use OCA\DuplicateFinder\Service\{ReviewGroupService,ReviewShareService};
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use OCP\{IGroupManager,IGroup,IUser};
use PHPUnit\Framework\TestCase;
class ReviewGroupServiceTest extends TestCase {
    private function fixture(array $backends=['Database']): array {
        $shares=$this->createMock(ReviewShareService::class);
        $page=['appRef'=>7,'observed'=>['id'=>7,'etag'=>'v1'],'observedAt'=>10,
            'items'=>[['id'=>'42','type'=>1,'recipient'=>'team','permissions'=>1]]];
        $shares->method('page')->with(7,1,1,0,25)->willReturn($page);
        $group=$this->createMock(IGroup::class);
        $group->method('getGID')->willReturn('team');
        $group->method('getBackendNames')->willReturn($backends);
        $groups=$this->createMock(IGroupManager::class);
        $groups->method('get')->with('team')->willReturn($group);
        return [new ReviewGroupService($shares,$groups),$group,$page];
    }
    public function testExplicitPageUsesBoundedLookaheadAndDoesNotClaimAccess(): void {
        [$service,$group]=$this->fixture();
        $users=[];
        foreach (['a','b'] as $uid) {
            $user=$this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('isEnabled')->willReturn(true);
            $users[]=$user;
        }
        $group->expects($this->once())->method('searchUsers')->with('',2,0)->willReturn($users);
        $result=$service->page(7,1,0,'42',0,1);
        $this->assertSame([['uid'=>'a','enabled'=>true,'accessStatus'=>'not_resolved']],$result['members']);
        $this->assertSame(1,$result['nextOffset']);
        $this->assertFalse($result['complete']);
        $this->assertSame('group_membership_only',$result['scope']);
    }
    public function testMultipleOrUnknownBackendsNeverEnumerate(): void {
        foreach ([['Database','LDAP'],['LDAP'],[]] as $backends) {
            [$service,$group]=$this->fixture($backends);
            $group->expects($this->never())->method('searchUsers');
            $result=$service->page(7,1,0,'42',0,25);
            $this->assertSame('backend_not_qualified',$result['status']);
            $this->assertNull($result['nextOffset']);
            $this->assertFalse($result['complete']);
        }
    }
    public function testForeignShareRejectedBeforeEnumeration(): void {
        [$service,$group]=$this->fixture();
        $group->expects($this->never())->method('searchUsers');
        $this->expectException(EvidenceConflictException::class);
        $service->page(7,1,0,'43',0,25);
    }
    public function testProviderIgnoringBoundFailsClosed(): void {
        [$service,$group]=$this->fixture();
        $group->method('searchUsers')->willReturn(array_fill(0,3,$this->createMock(IUser::class)));
        $this->expectException(\RuntimeException::class);
        $service->page(7,1,0,'42',0,1);
    }
}
