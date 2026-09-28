<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;
use OCA\DuplicateFinder\Service\{PlanGroupService,ReviewGroupService};
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use PHPUnit\Framework\TestCase;
class PlanGroupServiceTest extends TestCase {
    private function page(): array {
        return ['appRef'=>7,'observed'=>['id'=>7,'etag'=>'v1'],'observedAt'=>10,
            'sharePage'=>['observedAt'=>9,'items'=>[['id'=>'42','recipient'=>'team']]],
            'shareId'=>'42','groupId'=>'team','status'=>'observed','complete'=>false,
            'members'=>[['uid'=>'alice','enabled'=>true,'accessStatus'=>'not_resolved']],'nextOffset'=>null];
    }
    private function selection(): array {
        return ['query'=>['depth'=>1,'shareOffset'=>0,'shareId'=>'42','offset'=>0],'expected'=>$this->page()];
    }
    public function testRevalidatesAndStoresOnlySelectedHistoricalMembership(): void {
        $api=$this->createMock(ReviewGroupService::class);
        $actual=$this->page();$actual['observedAt']=20;$actual['sharePage']['observedAt']=19;
        $api->expects($this->once())->method('page')->with(7,1,0,'42',0,25)->willReturn($actual);
        $saved=(new PlanGroupService($api))->capture(7,[$this->selection()],['id'=>7,'etag'=>'v1']);
        $this->assertSame($actual,$saved['pages'][0]['page']);
        $this->assertFalse($saved['complete']);
        $this->assertSame('selected_membership_pages_only',$saved['coverage']);
    }
    public function testMembershipStatusAndSharingChangesConflict(): void {
        foreach (['members','enabled','share','next','status'] as $change) {
            $api=$this->createMock(ReviewGroupService::class);$actual=$this->page();
            if ($change==='members') $actual['members']=[];
            if ($change==='enabled') $actual['members'][0]['enabled']=false;
            if ($change==='share') $actual['sharePage']['items']=[];
            if ($change==='next') $actual['nextOffset']=25;
            if ($change==='status') $actual['status']='backend_not_qualified';
            $api->method('page')->willReturn($actual);
            try {(new PlanGroupService($api))->capture(7,[$this->selection()],['id'=>7,'etag'=>'v1']);$this->fail('Changed membership accepted');}
            catch(EvidenceConflictException $e){$this->assertNotEmpty($e->getMessage());}
        }
    }
    public function testRejectsForeignBindingBeforeQuery(): void {
        $api=$this->createMock(ReviewGroupService::class);$api->expects($this->never())->method('page');
        $this->expectException(EvidenceConflictException::class);
        (new PlanGroupService($api))->capture(7,[$this->selection()],['id'=>7,'etag'=>'v2']);
    }
    public function testDuplicatePagesAreRejectedBeforeQuery(): void {
        $api=$this->createMock(ReviewGroupService::class);$api->expects($this->never())->method('page');
        $this->expectException(\InvalidArgumentException::class);
        (new PlanGroupService($api))->capture(7,[$this->selection(),$this->selection()],['id'=>7,'etag'=>'v1']);
    }
    public function testEmptySelectionDoesNotEnumerate(): void {
        $api=$this->createMock(ReviewGroupService::class);$api->expects($this->never())->method('page');
        $result=(new PlanGroupService($api))->capture(7,[],['id'=>7]);
        $this->assertSame('not_observed',$result['coverage']);$this->assertFalse($result['complete']);
    }
}
