<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;

use OCA\DuplicateFinder\Db\CheckJobMapper;
use OCA\DuplicateFinder\Service\{CheckJobService,ReviewService,EvidenceSnapshotService,EvidenceService,PreviewArtifactService};
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class CheckJobServiceTest extends TestCase {
    private function fixture(): array {
        $ref=['id'=>1,'indexOwner'=>'alice','indexPath'=>'/alice/files/a','owner'=>'alice','path'=>'/alice/files/a','storageId'=>'home::alice','nodeId'=>4,'etag'=>'v1','size'=>12,'mtime'=>100,'availability'=>'available'];
        $review=$this->createMock(ReviewService::class);
        $review->method('reference')->willReturn($ref);
        $clock=$this->createMock(ITimeFactory::class);
        $clock->method('getTime')->willReturn(1000);
        $mapper=$this->createMock(CheckJobMapper::class);
        $evidence=$this->createMock(EvidenceService::class);
        $previews=$this->createMock(PreviewArtifactService::class);
        $service=new CheckJobService($mapper,$review,new EvidenceSnapshotService($review),$evidence,$previews,$clock);
        $payload=['idempotencyKey'=>'request-1','members'=>[['appRef'=>1,'expected'=>$ref]],'preview'=>true];
        $mapper->method('insert')->willReturnArgument(3);
        return [$service,$mapper,$payload,$evidence,$previews];
    }
    public function testSelectionQueuesMetadataSnapshotWithoutExposingLease(): void {
        [$s,$m,$p]=$this->fixture();
        $job=$s->create($p,'admin');
        $this->assertSame('queued',$job['state']);
        $this->assertSame('pending',$job['items'][0]['status']);
        $this->assertSame('v1',$job['items'][0]['snapshot']['etag']);
        $this->assertSame(0,$job['completedCount']);
        $this->assertArrayNotHasKey('leaseToken',$job);
        $this->assertArrayNotHasKey('request',$job);
    }
    public function testChangedSelectionIsRejected(): void {
        [$s,$m,$p]=$this->fixture(); $p['members'][0]['expected']['etag']='old';
        $this->expectException(EvidenceConflictException::class); $s->create($p,'admin');
    }
    public function testRepeatedReferencesAreRejected(): void {
        [$s,$m,$p]=$this->fixture(); $p['members'][]=$p['members'][0];
        $this->expectException(\InvalidArgumentException::class); $s->create($p,'admin');
    }
    public function testNativePathInputIsRejected(): void {
        [$s,$m,$p]=$this->fixture(); $p['nativePath']='/data/a';
        $this->expectException(\InvalidArgumentException::class); $s->create($p,'admin');
    }
    private function running(CheckJobService $s,array $p): array {
        $job=$s->create($p,'admin');
        $job['state']='running';$job['leaseToken']=str_repeat('a',64);$job['leaseUntil']=1120;$job['rowVersion']=1;
        return $job;
    }
    public function testExpiredJobIsInterruptedAndNeverClaimedAgain(): void {
        [$s,$m,$p]=$this->fixture();$job=$this->running($s,$p);$job['leaseUntil']=999;
        $m->method('find')->willReturn($job);$m->method('compareAndSwap')->willReturn(true);
        $result=$s->get($job['jobId']);$this->assertSame('interrupted',$result['state']);
        $this->assertFalse($s->heartbeat($job['jobId'],str_repeat('a',64)));
    }
    public function testCancellationPreventsCompletion(): void {
        [$s,$m,$p]=$this->fixture();$job=$this->running($s,$p);$job['state']='cancelled';
        $m->method('find')->willReturn($job);
        $this->expectException(EvidenceConflictException::class);
        $s->completeItem($job['jobId'],str_repeat('a',64),1,['status'=>'error']);
    }
    public function testLastItemCompletesAndRejectsForeignEvidence(): void {
        [$s,$m,$p,$e]=$this->fixture();$job=$this->running($s,$p);
        $m->method('find')->willReturn($job);$m->method('compareAndSwap')->willReturn(true);
        $e->method('getEvidence')->willReturn(null);
        $this->expectException(\InvalidArgumentException::class);
        $s->completeItem($job['jobId'],str_repeat('a',64),1,['status'=>'passed','evidenceId'=>3]);
    }
    public function testLastErrorCompletesWithNoLeaseInPublicResponse(): void {
        [$s,$m,$p]=$this->fixture();$job=$this->running($s,$p);
        $m->method('find')->willReturn($job);$m->method('compareAndSwap')->willReturn(true);
        $result=$s->completeItem($job['jobId'],str_repeat('a',64),1,['status'=>'error','reason'=>'decoder_failed']);
        $this->assertSame('completed',$result['state']);$this->assertSame(1,$result['completedCount']);
        $this->assertArrayNotHasKey('leaseToken',$result);
    }
    public function testLostCasCannotAcceptCompletion(): void {
        [$s,$m,$p]=$this->fixture();$job=$this->running($s,$p);
        $m->method('find')->willReturn($job);$m->method('compareAndSwap')->willReturn(false);
        $this->expectException(EvidenceConflictException::class);
        $s->completeItem($job['jobId'],str_repeat('a',64),1,['status'=>'error']);
    }
    public function testExactRetryReturnsStoredJobEvenIfBrowserMetadataIsNowOld(): void {
        [$s,$m,$p]=$this->fixture();$original=$s->create($p,'admin');
        $m->method('retry')->willReturn($original);$p['members'][0]['expected']['etag']='old';
        $this->assertSame($original,$s->create($p,'admin'));
    }
    public function testClaimUsesOneCasAndReturnsOnlySelectedPendingItems(): void {
        [$s,$m,$p]=$this->fixture();$job=$s->create($p,'admin');$job['rowVersion']=0;
        $m->method('candidates')->willReturn([$job]);$m->method('compareAndSwap')->willReturn(true);
        $claim=$s->claim();$this->assertSame($job['jobId'],$claim['jobId']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/',$claim['leaseToken']);
        $this->assertSame([1],array_column($claim['items'],'appRef'));
        $this->assertArrayNotHasKey('creator',$claim);
    }
    public function testLostClaimRaceReturnsNoJob(): void {
        [$s,$m,$p]=$this->fixture();$job=$s->create($p,'admin');$job['rowVersion']=0;
        $m->method('candidates')->willReturn([$job]);$m->method('compareAndSwap')->willReturn(false);
        $this->assertNull($s->claim());
    }
    public function testWrongLeaseTokenCannotRenew(): void {
        [$s,$m,$p]=$this->fixture();$job=$this->running($s,$p);$m->method('find')->willReturn($job);
        $this->assertFalse($s->heartbeat($job['jobId'],str_repeat('b',64)));
    }
    public function testQueuedJobCanBeCancelledWithoutClaim(): void {
        [$s,$m,$p]=$this->fixture();$job=$s->create($p,'admin');$job['rowVersion']=0;
        $m->method('find')->willReturn($job);$m->method('compareAndSwap')->willReturn(true);
        $cancelled=$s->cancel($job['jobId']);$this->assertSame('cancelled',$cancelled['state']);
        $this->assertSame('pending',$cancelled['items'][0]['status']);
    }
    public function testWrongSelectedReferenceCannotBeCompleted(): void {
        [$s,$m,$p]=$this->fixture();$job=$this->running($s,$p);$m->method('find')->willReturn($job);
        $this->expectException(EvidenceConflictException::class);
        $s->completeItem($job['jobId'],str_repeat('a',64),99,['status'=>'error']);
    }
    public function testPassedResultRequiresPersistedEvidence(): void {
        [$s,$m,$p]=$this->fixture();$job=$this->running($s,$p);$m->method('find')->willReturn($job);
        $this->expectException(\InvalidArgumentException::class);
        $s->completeItem($job['jobId'],str_repeat('a',64),1,['status'=>'passed']);
    }
    public function testBoundEvidenceAndPreviewAreAcceptedButNeverPromotedToFresh(): void {
        [$s,$m,$p,$e,$v]=$this->fixture();$job=$this->running($s,$p);$snapshot=$job['items'][0]['snapshot'];
        $m->method('find')->willReturn($job);$m->method('compareAndSwap')->willReturn(true);
        $e->method('getEvidence')->willReturn(['id'=>3,'appRef'=>1,'record'=>['before'=>$snapshot,'after'=>$snapshot,'report'=>['status'=>'passed']]]);
        $v->method('getPreview')->willReturn(['id'=>5,'appRef'=>1,'evidenceId'=>3,'record'=>['boundSnapshot'=>$snapshot]]);
        $result=$s->completeItem($job['jobId'],str_repeat('a',64),1,['status'=>'passed','evidenceId'=>3,'previewId'=>5]);
        $this->assertSame('completed',$result['state']);$this->assertSame(5,$result['items'][0]['previewId']);
        $this->assertSame(['native_revision_not_rechecked'],$result['limitations']);
    }
    public function testEvidenceFromDifferentRevisionIsRejected(): void {
        [$s,$m,$p,$e]=$this->fixture();$job=$this->running($s,$p);$snapshot=$job['items'][0]['snapshot'];$snapshot['etag']='old';
        $m->method('find')->willReturn($job);
        $e->method('getEvidence')->willReturn(['id'=>3,'appRef'=>1,'record'=>['before'=>$snapshot,'after'=>$snapshot,'report'=>['status'=>'passed']]]);
        $this->expectException(\InvalidArgumentException::class);
        $s->completeItem($job['jobId'],str_repeat('a',64),1,['status'=>'passed','evidenceId'=>3]);
    }
    public function testMismatchedPreviewCannotBeAttached(): void {
        [$s,$m,$p,$e,$v]=$this->fixture();$job=$this->running($s,$p);$snapshot=$job['items'][0]['snapshot'];
        $m->method('find')->willReturn($job);
        $e->method('getEvidence')->willReturn(['id'=>3,'appRef'=>1,'record'=>['before'=>$snapshot,'after'=>$snapshot,'report'=>['status'=>'passed']]]);
        $v->method('getPreview')->willReturn(['id'=>6,'appRef'=>1,'evidenceId'=>3,'record'=>['boundSnapshot'=>$snapshot]]);
        $this->expectException(\InvalidArgumentException::class);
        $s->completeItem($job['jobId'],str_repeat('a',64),1,['status'=>'passed','evidenceId'=>3,'previewId'=>5]);
    }
    public function testOversizedPageIsRejected(): void {
        [$s]=$this->fixture();$this->expectException(\InvalidArgumentException::class);$s->listing(0,101);
    }
    public function testOversizedSelectionIsRejected(): void {
        [$s,$m,$p]=$this->fixture();$p['members']=array_fill(0,21,$p['members'][0]);
        $this->expectException(\InvalidArgumentException::class);$s->create($p,'admin');
    }
    public function testOversizedRequestIsRejected(): void {
        [$s,$m,$p]=$this->fixture();$p['members'][0]['expected']['path']=str_repeat('x',262144);
        $this->expectException(\InvalidArgumentException::class);$s->create($p,'admin');
    }
    public function testPostImportStaleResultRetainsHistoricalPassedEvidence(): void {
        [$s,$m,$p,$e,$v]=$this->fixture();$job=$this->running($s,$p);$snapshot=$job['items'][0]['snapshot'];
        $m->method('find')->willReturn($job);$m->method('compareAndSwap')->willReturn(true);
        $e->method('getEvidence')->willReturn(['id'=>3,'appRef'=>1,'record'=>['before'=>$snapshot,'after'=>$snapshot,'report'=>['status'=>'passed']]]);
        $v->method('getPreview')->willReturn(['id'=>5,'appRef'=>1,'evidenceId'=>3,'record'=>['boundSnapshot'=>$snapshot]]);
        $result=$s->completeItem($job['jobId'],str_repeat('a',64),1,['status'=>'stale','evidenceId'=>3,'previewId'=>5,'reason'=>'native_revision_changed_after_import']);
        $this->assertSame('stale',$result['items'][0]['status']);$this->assertSame(3,$result['items'][0]['evidenceId']);
    }
}
