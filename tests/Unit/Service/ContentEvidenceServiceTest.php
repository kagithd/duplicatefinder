<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;
use OCA\DuplicateFinder\Service\{ContentEvidenceService,EvidenceSnapshotService};
use OCA\DuplicateFinder\Db\ContentEvidenceMapper;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use PHPUnit\Framework\TestCase;

class ContentEvidenceServiceTest extends TestCase {
    private function report(): array {
        $s=['appRef'=>7,'size'=>12,'revisionToken'=>str_repeat('a',64)];
        $n=['dev'=>'1','ino'=>'2','size'=>'12','mtime_ns'=>'1727000000123456789','ctime_ns'=>'1727000000987654321','regular'=>true];
        return ['appRef'=>7,'status'=>'read','reason'=>'content_read','algorithm'=>'sha256','digest'=>str_repeat('b',64),
            'formatStatus'=>'not_checked','actionEligible'=>false,'scope'=>'original_bytes_observed',
            'nativeFreshness'=>'observed_not_locked_after','checker'=>['id'=>'synthetic.content','version'=>'1','ruleVersion'=>'1'],
            'before'=>$s,'after'=>$s,'revision_before'=>$n,'revision_after'=>$n,'limits'=>['max_bytes'=>1024]];
    }
    private function setupService(array $report): array {
        $mapper=$this->createMock(ContentEvidenceMapper::class);
        $snapshots=$this->createMock(EvidenceSnapshotService::class);
        $snapshots->method('snapshot')->with(7)->willReturn($report['after']);
        return [new ContentEvidenceService($mapper,$snapshots),$mapper];
    }
    public function testAppendsExactHistoricalContentWithoutDecoderVerdict(): void {
        $r=$this->report();[$service,$mapper]=$this->setupService($r);
        $mapper->expects($this->once())->method('append')->willReturnCallback(function($ref,$time,$json)use($r){
            $record=json_decode($json,true,512,JSON_THROW_ON_ERROR);
            $this->assertSame(7,$ref);$this->assertGreaterThan(0,$time);
            $this->assertSame(['schemaVersion'=>1,'kind'=>'content','report'=>$r],$record);
            return 19;
        });
        $this->assertSame(19,$service->record(7,$r));
    }
    public function testInvalidReportsCannotPersist(): void {
        $valid=$this->report();
        $cases=[];
        foreach(['status'=>'passed','algorithm'=>'md5','digest'=>'bad','formatStatus'=>'passed','actionEligible'=>true,'appRef'=>8] as $k=>$v) {
            $r=$valid;$r[$k]=$v;$cases[]=$r;
        }
        $r=$valid;$r['revision_after']['ino']='3';$cases[]=$r;
        $r=$valid;$r['revision_after']['mtime_ns']=1727000000123456789;$cases[]=$r;
        $r=$valid;$r['revision_before']['regular']=false;$cases[]=$r;
        $r=$valid;$r['after']['size']=13;$cases[]=$r;
        $r=$valid;$r['limits']['max_bytes']=11;$cases[]=$r;
        foreach($cases as $r) {
            [$service,$mapper]=$this->setupService($valid);
            $mapper->expects($this->never())->method('append');
            try {$service->record(7,$r);$this->fail('Invalid report accepted');}
            catch(\InvalidArgumentException $e){$this->assertNotSame('',$e->getMessage());}
        }
    }
    public function testChangedServerSnapshotCannotPersist(): void {
        $r=$this->report();$current=$r;$current['after']['revisionToken']=str_repeat('c',64);
        [$service,$mapper]=$this->setupService($current);
        $mapper->expects($this->never())->method('append');
        $this->expectException(EvidenceConflictException::class);
        $service->record(7,$r);
    }
    public function testHistoryNeverPromotesMetadataMatchToCurrent(): void {
        $r=$this->report();[$service,$mapper]=$this->setupService($r);
        $row=['id'=>19,'created_at'=>100,'record_json'=>json_encode(['schemaVersion'=>1,'kind'=>'content','report'=>$r])];
        $mapper->expects($this->once())->method('history')->with(7,0,2)->willReturn([$row,array_merge($row,['id'=>18])]);
        $page=$service->history(7,0,1);
        $this->assertCount(1,$page['items']);$this->assertSame(19,$page['nextCursor']);
        $this->assertSame('unverifiable',$page['items'][0]['usability']);
        $this->assertSame('native_revision_not_rechecked',$page['items'][0]['reason']);
        $this->assertFalse($page['items'][0]['actionEligible']);
    }
    public function testMissingAndChangedFilesKeepHistoryReadable(): void {
        foreach([false,true]as$missing) {
            $r=$this->report();$mapper=$this->createMock(ContentEvidenceMapper::class);
            $snapshots=$this->createMock(EvidenceSnapshotService::class);
            if($missing)$snapshots->method('snapshot')->willThrowException(new \RuntimeException('gone'));
            else $snapshots->method('snapshot')->willReturn(array_merge($r['after'],['size'=>13]));
            $mapper->method('history')->willReturn([['id'=>19,'created_at'=>100,'record_json'=>json_encode(['schemaVersion'=>1,'kind'=>'content','report'=>$r])]]);
            $item=(new ContentEvidenceService($mapper,$snapshots))->history(7)['items'][0];
            $this->assertSame($missing?'unverifiable':'stale',$item['usability']);
            $this->assertSame($r,$item['record']['report']);
        }
    }
}
