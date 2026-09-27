<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;
use OCA\DuplicateFinder\Service\PlanService;
use OCA\DuplicateFinder\Service\PlanShareService;
use OCA\DuplicateFinder\Service\ReviewService;
use OCA\DuplicateFinder\Service\EvidenceService;
use OCA\DuplicateFinder\Db\PlanMapper;
use OCA\DuplicateFinder\Db\ReviewMapper;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use PHPUnit\Framework\TestCase;
class PlanServiceTest extends TestCase  {
    private function fixture(array $overrides = [], bool $alias = false, ?string $candidate = null, ?EvidenceService $evidence = null, ?\OCA\DuplicateFinder\Service\PreviewArtifactService $previews = null, ?PlanShareService $sharing = null): array  {
        $r=['id'=>1,'indexOwner'=>'alice','indexPath'=>'/alice/files/a','owner'=>'alice','path'=>'/alice/files/a','storageId'=>'home::alice','nodeId'=>4,'etag'=>'v1','size'=>12,'mtime'=>100,'availability'=>'available','candidateHash'=>str_repeat('a',64)];
        $r = array_merge($r, $overrides);
        $review=$this->createMock(ReviewService::class);
        $review->method('reference')->willReturnCallback(fn($id)=>array_merge($r,['id'=>$id,'nodeId'=>$r['nodeId'] === null ? null : ($alias ? 4 : $id+3)]));
        $index=$this->createMock(ReviewMapper::class);
        $index->method('candidateHash')->willReturn($candidate ?? str_repeat('a',64));
        $mapper=$this->createMock(PlanMapper::class);
        $service=new PlanService($mapper,$review,$index,$evidence ?? $this->createMock(EvidenceService::class), $previews ?? $this->createMock(\OCA\DuplicateFinder\Service\PreviewArtifactService::class), $sharing ?? $this->createMock(PlanShareService::class));
        $member=['appRef'=>1,'action'=>'keep','expected'=>$r,'evidenceIds'=>[],'manualAssessment'=>['status'=>'not_assessed','note'=>''],'reason'=>''];
        return [$service,$mapper,['hash'=>str_repeat('a',64),'members'=>[$member],'indexActions'=>[],'note'=>'','idempotencyKey'=>'request-1']];
    }
    public function testPersistsServerValidatedSharingRatherThanBrowserFields(): void {
        $sharing=$this->createMock(PlanShareService::class);
        [$s,$m,$p]=$this->fixture([],false,null,null,null,$sharing);
        $selected=[['query'=>['depth'=>0,'type'=>0,'offset'=>0],'expected'=>['appRef'=>1]]];
        $p['members'][0]['sharePages']=$selected;
        $server=['complete'=>false,'coverage'=>'selected_pages_only','pages'=>[['page'=>['observedAt'=>200]]]];
        $sharing->expects($this->once())->method('capture')->with(1,$selected,$p['members'][0]['expected'])->willReturn($server);
        $m->expects($this->once())->method('save')->willReturnCallback(function($id,$prev,$key,$digest,$record) use ($server) {
            $this->assertSame($server,$record['members'][0]['sharing']);return $record;
        });
        $s->create($p,'admin');
    }
    public function testSharingConflictPreventsPersistence(): void {
        $sharing=$this->createMock(PlanShareService::class);
        [$s,$m,$p]=$this->fixture([],false,null,null,null,$sharing);
        $p['members'][0]['sharePages']=[];
        $sharing->method('capture')->willThrowException(new EvidenceConflictException('Shares changed'));
        $m->expects($this->never())->method('save');
        $this->expectException(EvidenceConflictException::class);
        $s->create($p,'admin');
    }
    public function testPersistsOnlyExplicitSelectionAndNonExecutableDraft(): void  {
        [$s,$m,$p]=$this->fixture();
        $m->expects($this->once())->method('save')->willReturnCallback(function($id,$prev,$key,$digest,$record) {
            $this->assertCount(1,$record['members']);$this->assertFalse($record['executable']);$this->assertSame('admin',$record['creator']);return $record;
        }
        );
        $s->create($p,'admin');
    }
    public function testChangedMetadataConflictsBeforePersistence(): void  {
        [$s,$m,$p]=$this->fixture();
        $p['members'][0]['expected']['etag']='old';
        $m->expects($this->never())->method('save');
        $this->expectException(EvidenceConflictException::class);
        $s->create($p,'admin');
    }
    public function testRemoveRequiresKeep(): void  {
        [$s,$m,$p]=$this->fixture();
        $p['members'][0]['action']='remove';
        $m->expects($this->never())->method('save');
        $this->expectException(\InvalidArgumentException::class);
        $s->create($p,'admin');
    }
    public function testUnknownActionRejected(): void  {
        [$s,$m,$p]=$this->fixture();
        $p['members'][0]['action']='delete';
        $this->expectException(\InvalidArgumentException::class);
        $s->create($p,'admin');
    }
    public function testBrowserReferenceIdAloneIsInsufficient(): void  {
        [$s,$m,$p]=$this->fixture();
        $p['members'][0]['expected']=['id'=>1];
        $this->expectException(EvidenceConflictException::class);
        $s->create($p,'admin');
    }
    public function testUnavailableReferenceCannotBeKept(): void  {
        [$s,$m,$p]=$this->fixture();
        // A stale browser claim must conflict, even before unavailable-action rules.
        $p['members'][0]['expected']['availability']='unavailable';
        $this->expectException(EvidenceConflictException::class);
        $s->create($p,'admin');
    }
    public function testForeignEvidenceCannotBeAccepted(): void  {
        [$s,$m,$p]=$this->fixture();
        $p['members'][0]['evidenceIds']=[81];
        $this->expectException(\InvalidArgumentException::class);
        $s->create($p,'admin');
    }
    public function testExactRetryReturnsOriginalWithoutMetadataReadOrNewRevision(): void  {
        [$s,$m,$p]=$this->fixture();
        $original=['revision'=>1,'executable'=>false];
        $m->method('retry')->willReturn($original);
        $m->expects($this->never())->method('save');
        $this->assertSame($original,$s->create($p,'admin'));
    }
    public function testNonEmptyIndexActionsAreRejected(): void  {
        [$s,$m,$p]=$this->fixture();
        $p['indexActions']=[['appRef'=>1,'action'=>'clear']];
        $this->expectException(\InvalidArgumentException::class);
        $s->create($p,'admin');
    }
    public function testUnavailableReferenceCanOnlyBeExcluded(): void {
        [$s, $m, $p] = $this->fixture(['availability' => 'unavailable']);
        $this->expectException(\InvalidArgumentException::class);
        $s->create($p, 'admin');
    }
    public function testUnavailableExclusionPreservesIndexedPath(): void {
        [$s, $m, $p] = $this->fixture(['availability' => 'unavailable', 'owner' => null, 'path' => null, 'nodeId' => null]);
        $p['members'][0]['action'] = 'exclude';
        $m->method('save')->willReturnCallback(function ($id, $prev, $key, $digest, $record) {
            $this->assertSame('/alice/files/a', $record['members'][0]['observed']['indexPath']);
            return $record;
        });
        $s->create($p, 'admin');
    }
    public function testPhysicalKeepRemoveConflictRejected(): void {
        [$s, $m, $p] = $this->fixture([], true);
        $second = $p['members'][0];
        $second['appRef'] = 2;
        $second['expected']['id'] = 2;
        $second['action'] = 'remove';
        $p['members'][] = $second;
        $this->expectException(\InvalidArgumentException::class);
        $s->create($p, 'admin');
    }
    /** @dataProvider sharedMountActions */
    public function testSharedMountAliasesCannotHaveConflictingActions(string $firstAction, string $secondAction, bool $conflict): void {
        $owner = ['id'=>1,'indexOwner'=>'alice','indexPath'=>'/alice/files/photos/a.png','owner'=>'alice',
            'path'=>'/alice/files/photos/a.png','storageId'=>'home::alice','nodeId'=>42,'etag'=>'v1',
            'size'=>12,'mtime'=>100,'availability'=>'available','candidateHash'=>str_repeat('a',64)];
        $recipient = array_merge($owner, ['id'=>2,'indexOwner'=>'bob','indexPath'=>'/bob/files/shared/a.png',
            'path'=>'/bob/files/shared/a.png','storageId'=>'shared::/shared']);
        $review = $this->createMock(ReviewService::class);
        $review->method('reference')->willReturnCallback(fn($id) => $id === 1 ? $owner : $recipient);
        $index = $this->createMock(ReviewMapper::class);
        $index->method('candidateHash')->willReturn(str_repeat('a',64));
        $mapper = $this->createMock(PlanMapper::class);
        if ($conflict) $mapper->expects($this->never())->method('save');
        else $mapper->expects($this->once())->method('save')->willReturnCallback(static fn($id,$prev,$key,$digest,$record) => $record);
        $service = new PlanService($mapper,$review,$index,$this->createMock(EvidenceService::class),
            $this->createMock(\OCA\DuplicateFinder\Service\PreviewArtifactService::class), $this->createMock(PlanShareService::class));
        $selection = static fn($ref,$action,$observed) => ['appRef'=>$ref,'action'=>$action,'expected'=>$observed,
            'evidenceIds'=>[],'manualAssessment'=>['status'=>'not_assessed','note'=>''],'reason'=>''];
        $payload = ['hash'=>str_repeat('a',64),'members'=>[$selection(1,$firstAction,$owner),$selection(2,$secondAction,$recipient)],
            'indexActions'=>[],'note'=>'','idempotencyKey'=>'shared-alias-1'];
        if ($conflict) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Conflicting or repeated physical action');
        }
        $result = $service->create($payload,'admin');
        if (!$conflict) $this->assertCount(2, $result['members']);
    }
    public static function sharedMountActions(): array {
        return [['keep','remove',true],['remove','keep',true],['remove','remove',true],['keep','keep',false],['keep','exclude',false]];
    }
    public function testChangedCandidateHashConflicts(): void {
        [$s, $m, $p] = $this->fixture([], false, str_repeat('b', 64));
        $this->expectException(EvidenceConflictException::class);
        $s->create($p, 'admin');
    }    public function testEvidenceBudgetStopsFetchingBeforeOversizedRevisionIsBuilt(): void
    {
        $evidence = $this->createMock(EvidenceService::class);
        $calls = 0;
        $evidence->method('getEvidence')->willReturnCallback(function ($id, $ref) use (&$calls) {
            $calls++;
            return ['id' => $id, 'appRef' => $ref, 'record' => ['report' => str_repeat('x', 65536)]];
        });
        [$service, $mapper, $payload] = $this->fixture([], false, null, $evidence);
        $payload['members'][0]['evidenceIds'] = range(1, 20);
        $second = $payload['members'][0];
        $second['appRef'] = 2;
        $second['expected']['id'] = 2;
        $second['expected']['nodeId'] = 5;
        $second['evidenceIds'] = range(21, 40);
        $payload['members'][] = $second;
        $mapper->expects($this->never())->method('save');
        try {
            $service->create($payload, 'admin');
            $this->fail('Oversized evidence revision accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Saved revision too large', $e->getMessage());
            $this->assertLessThan(40, $calls, 'Stop fetching as soon as the cumulative record budget is exceeded');
        }
    }
    private function manualFixture(array $changes = []): array {
        $preview = $this->createMock(\OCA\DuplicateFinder\Service\PreviewArtifactService::class);
        $evidence = $this->createMock(EvidenceService::class);
        $evidence->method('getEvidence')->willReturn(['id'=>7, 'appRef'=>1]);
        [$service,$mapper,$payload] = $this->fixture([],false,null,$evidence,$preview);
        $snapshot = ['appRef'=>1];
        foreach (['indexOwner','indexPath','owner','path','nodeId','storageId','etag','size','mtime'] as $key) $snapshot[$key] = $payload['members'][0]['expected'][$key];
        $artifact = ['id'=>12,'appRef'=>1,'evidenceId'=>7,'record'=>['boundSnapshot'=>$snapshot,'sha256'=>str_repeat('b',64)], 'validityScope'=>'original_first_frame_scaled'];
        $artifact = array_replace_recursive($artifact,$changes);
        $preview->method('getPreview')->with(1,7)->willReturn($artifact);
        $payload['members'][0]['evidenceIds']=[7];
        $payload['members'][0]['manualAssessment']=['status'=>'content_visible','note'=>'Visible subject', 'source'=>['kind'=>'original_preview','evidenceId'=>7,'previewId'=>12,'sha256'=>str_repeat('b',64),'scope'=>'original_first_frame_scaled']];
        return [$service,$mapper,$payload];
    }
    public function testManualAssessmentPreservesExactOriginalPreviewProvenance(): void {
        [$service,$mapper,$payload]=$this->manualFixture();
        $mapper->method('save')->willReturnCallback(fn($id,$prev,$key,$digest,$record)=>$record);
        $saved=$service->create($payload,'reviewer');
        $this->assertSame($payload['members'][0]['manualAssessment'],$saved['members'][0]['manualAssessment']);
        $this->assertFalse($saved['executable']);
        $this->assertContains('native_revision_not_rechecked',$saved['limitations']);
    }
    public function testPositiveManualAssessmentRequiresExplicitSource(): void {
        [$service,$mapper,$payload]=$this->fixture();
        $payload['members'][0]['manualAssessment']['status']='content_visible';
        $mapper->expects($this->never())->method('save');
        $this->expectException(\InvalidArgumentException::class);
        $service->create($payload,'reviewer');
    }
    public function testManualPreviewMustMatchReferenceRevisionAndDigest(): void {
        foreach ([['id'=>13],['appRef'=>2],['evidenceId'=>8],['record'=>['sha256'=>str_repeat('c',64)]],['record'=>['boundSnapshot'=>['etag'=>'old']]],['validityScope'=>'all_frames']] as $change) {
            [$service,$mapper,$payload]=$this->manualFixture($change);
            $mapper->expects($this->never())->method('save');
            try { $service->create($payload,'reviewer'); $this->fail('Mismatched preview accepted'); }
            catch (EvidenceConflictException $error) { $this->assertNotEmpty($error->getMessage()); }
        }
    }
    public function testManualSourceMustBeSelectedAndCannotClaimUnassessed(): void {
        foreach (['evidence','status','field','scope','id'] as $change) {
            [$service,$mapper,$payload]=$this->manualFixture();
            if ($change==='evidence') $payload['members'][0]['evidenceIds']=[];
            if ($change==='status') $payload['members'][0]['manualAssessment']['status']='not_assessed';
            if ($change==='field') $payload['members'][0]['manualAssessment']['source']['path']='/arbitrary';
            if ($change==='scope') $payload['members'][0]['manualAssessment']['source']['scope']='all_frames';
            if ($change==='id') $payload['members'][0]['manualAssessment']['source']['previewId']='12';
            $mapper->expects($this->never())->method('save');
            try { $service->create($payload,'reviewer'); $this->fail('Invalid source accepted'); }
            catch (\InvalidArgumentException $error) { $this->assertNotEmpty($error->getMessage()); }
        }
    }
}
