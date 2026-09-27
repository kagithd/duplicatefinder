<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;
use OCA\DuplicateFinder\Db\EvidenceMapper;
use OCA\DuplicateFinder\Service\EvidenceService;
use OCA\DuplicateFinder\Service\EvidenceSearchService;
use PHPUnit\Framework\TestCase;
class EvidenceSearchServiceTest extends TestCase {
    public function testBoundedSearchKeepsValidityAndReportsIncompleteProjection(): void {
        $mapper=$this->createMock(EvidenceMapper::class);
        $history=$this->createMock(EvidenceService::class);
        $mapper->expects($this->once())->method('latestSearch')->with(0,2,'corrupt','PNG')->willReturn([
            ['id'=>9,'app_ref'=>7,'created_at'=>10,'record_json'=>'{}','search_status'=>'corrupt','search_format'=>'PNG'],
            ['id'=>8,'app_ref'=>6,'created_at'=>9,'record_json'=>'{}','search_status'=>'corrupt','search_format'=>'PNG'],
        ]);
        $mapper->method('hasUnprojectedEvidence')->willReturn(true);
        $finding=['id'=>9,'appRef'=>7,'usability'=>'stale','reason'=>'nextcloud_revision_changed'];
        $history->expects($this->once())->method('getEvidence')->with(9,7)->willReturn($finding);
        $page=(new EvidenceSearchService($mapper,$history))->search(0,1,'corrupt','PNG');
        $this->assertSame(9,$page['nextCursor']);
        $this->assertFalse($page['metadataComplete']);
        $this->assertSame($finding,$page['items'][0]['evidence']);
        $this->assertSame('historical_reports_only',$page['scope']);
    }
    public function testInvalidReportRemainsVisibleWithoutClaimingValidity(): void {
        $mapper=$this->createMock(EvidenceMapper::class);$history=$this->createMock(EvidenceService::class);
        $mapper->method('latestSearch')->willReturn([['id'=>2,'app_ref'=>8,'created_at'=>1,'record_json'=>'broken','search_status'=>'invalid','search_format'=>null]]);
        $mapper->method('hasUnprojectedEvidence')->willReturn(false);
        $history->expects($this->never())->method('getEvidence');
        $page=(new EvidenceSearchService($mapper,$history))->search();
        $this->assertNull($page['nextCursor']);$this->assertTrue($page['metadataComplete']);
        $this->assertSame('invalid_report',$page['items'][0]['issue']);$this->assertNull($page['items'][0]['evidence']);
    }
    public function testInvalidInputNeverQueriesStorage(): void {
        $mapper=$this->createMock(EvidenceMapper::class);$mapper->expects($this->never())->method('latestSearch');
        $service=new EvidenceSearchService($mapper,$this->createMock(EvidenceService::class));
        foreach ([[-1,25,'',''],[0,101,'',''],[0,0,'',''],[0,25,'healthy',''],[0,25,'',str_repeat('x',129)]] as $args) {
            try {$service->search(...$args);$this->fail('Expected invalid input');}catch(\InvalidArgumentException $e){$this->addToAssertionCount(1);}
        }
    }
}