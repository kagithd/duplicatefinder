<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;
use OCA\DuplicateFinder\Service\{PlanShareService,ReviewShareService};
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use PHPUnit\Framework\TestCase;
class PlanShareServiceTest extends TestCase {
    private function page(): array {return ['appRef'=>7,'observed'=>['id'=>7,'etag'=>'v1'],'observedAt'=>10,'type'=>0,'anchor'=>['depth'=>1], 'items'=>[['recipient'=>'bob','permissions'=>1]],'complete'=>false,'limitations'=>['one_anchor_and_share_type_only']];}
    private function selection(): array {return ['query'=>['depth'=>1,'type'=>0,'offset'=>0],'expected'=>$this->page()];}
    public function testRechecksExactPageAndKeepsServerResultWithExplicitPartialCoverage(): void {
        $api=$this->createMock(ReviewShareService::class);$actual=$this->page();$actual['observedAt']=20;
        $api->expects($this->once())->method('page')->with(7,1,0,0,25)->willReturn($actual);
        $result=(new PlanShareService($api))->capture(7,[$this->selection()],['id'=>7,'etag'=>'v1']);
        $this->assertFalse($result['complete']);$this->assertSame($actual,$result['pages'][0]['page']);
        $this->assertSame(10,$result['pages'][0]['selectedObservedAt']);
    }
    public function testChangedRightsConflict(): void {
        $api=$this->createMock(ReviewShareService::class);$actual=$this->page();$actual['items'][0]['permissions']=31;
        $api->method('page')->willReturn($actual);$this->expectException(EvidenceConflictException::class);
        (new PlanShareService($api))->capture(7,[$this->selection()],['id'=>7,'etag'=>'v1']);
    }
    public function testChangedFileBindingConflicts(): void {
        $api=$this->createMock(ReviewShareService::class);$api->expects($this->never())->method('page');
        $this->expectException(EvidenceConflictException::class);
        (new PlanShareService($api))->capture(7,[$this->selection()],['id'=>7,'etag'=>'v2']);
    }
    public function testOversizedSelectionDoesNotQuery(): void {
        $api=$this->createMock(ReviewShareService::class);$api->expects($this->never())->method('page');
        $this->expectException(\InvalidArgumentException::class);
        (new PlanShareService($api))->capture(7,array_fill(0,21,$this->selection()),['id'=>7,'etag'=>'v1']);
    }
    public function testEmptySelectionDoesNotClaimNoShares(): void {
        $api=$this->createMock(ReviewShareService::class);$api->expects($this->never())->method('page');
        $result=(new PlanShareService($api))->capture(7,[],['id'=>7]);
        $this->assertFalse($result['complete']);$this->assertSame('not_observed',$result['coverage']);
    }
    /** @dataProvider changedPages */
    public function testChangedPageCannotBecomeSavedObservation(string $change): void {
        $expected=$this->page();
        $expected['items'][0]+=['recipientPath'=>'/bob/files/shared/photo.png','effectivePermissions'=>1,'deletable'=>false,'pathStatus'=>'observed'];
        $expected['nextOffset']=null;
        $actual=$expected;
        switch ($change) {
            case 'revoked': $actual['items']=[]; break;
            case 'recipient': $actual['items'][0]['recipient']='carol'; break;
            case 'renamed': $actual['items'][0]['recipientPath']='/bob/files/renamed/photo.png'; break;
            case 'unverifiable': $actual['items'][0]['pathStatus']='unverifiable'; break;
            case 'effective_rights': $actual['items'][0]['effectivePermissions']=27; break;
            case 'deletable': $actual['items'][0]['deletable']=true; break;
            case 'pagination': $actual['nextOffset']=25; break;
        }
        $api=$this->createMock(ReviewShareService::class);
        $api->method('page')->willReturn($actual);
        $selection=$this->selection();$selection['expected']=$expected;
        $this->expectException(EvidenceConflictException::class);
        (new PlanShareService($api))->capture(7,[$selection],['id'=>7,'etag'=>'v1']);
    }
    public static function changedPages(): array {
        return array_map(static fn($kind)=>[$kind],['revoked','recipient','renamed','unverifiable','effective_rights','deletable','pagination']);
    }
    public function testRepeatedSelectorIsRejectedBeforeAnyPageQuery(): void {
        $api=$this->createMock(ReviewShareService::class);$api->expects($this->never())->method('page');
        $this->expectException(\InvalidArgumentException::class);
        (new PlanShareService($api))->capture(7,[$this->selection(),$this->selection()],['id'=>7,'etag'=>'v1']);
    }
    public function testInvalidLaterSelectorIsRejectedBeforeFirstQuery(): void {
        $invalid=$this->selection();$invalid['query']['offset']='25';
        $api=$this->createMock(ReviewShareService::class);$api->expects($this->never())->method('page');
        $this->expectException(\InvalidArgumentException::class);
        (new PlanShareService($api))->capture(7,[$this->selection(),$invalid],['id'=>7,'etag'=>'v1']);
    }
    public function testObjectKeyOrderDoesNotCreateFalseConflict(): void {
        $actual=$this->page();$actual['observed']=array_reverse($actual['observed'],true);
        $actual['items'][0]=array_reverse($actual['items'][0],true);
        $api=$this->createMock(ReviewShareService::class);$api->method('page')->willReturn($actual);
        $result=(new PlanShareService($api))->capture(7,[$this->selection()],['etag'=>'v1','id'=>7]);
        $this->assertSame($actual,$result['pages'][0]['page']);
    }
}
