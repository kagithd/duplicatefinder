<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;
use OCA\DuplicateFinder\Service\PlanService;
use OCA\DuplicateFinder\Service\ReviewService;
use OCA\DuplicateFinder\Service\EvidenceService;
use OCA\DuplicateFinder\Db\PlanMapper;
use OCA\DuplicateFinder\Db\ReviewMapper;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use PHPUnit\Framework\TestCase;
class PlanServiceTest extends TestCase  {
    private function fixture(array $overrides = [], bool $alias = false, ?string $candidate = null, ?EvidenceService $evidence = null): array  {
        $r=['id'=>1,'indexOwner'=>'alice','indexPath'=>'/alice/files/a','owner'=>'alice','path'=>'/alice/files/a','storageId'=>'home::alice','nodeId'=>4,'etag'=>'v1','size'=>12,'mtime'=>100,'availability'=>'available','candidateHash'=>str_repeat('a',64)];
        $r = array_merge($r, $overrides);
        $review=$this->createMock(ReviewService::class);
        $review->method('reference')->willReturnCallback(fn($id)=>array_merge($r,['id'=>$id,'nodeId'=>$r['nodeId'] === null ? null : ($alias ? 4 : $id+3)]));
        $index=$this->createMock(ReviewMapper::class);
        $index->method('candidateHash')->willReturn($candidate ?? str_repeat('a',64));
        $mapper=$this->createMock(PlanMapper::class);
        $service=new PlanService($mapper,$review,$index,$evidence ?? $this->createMock(EvidenceService::class));
        $member=['appRef'=>1,'action'=>'keep','expected'=>$r,'evidenceIds'=>[],'manualAssessment'=>['status'=>'not_assessed','note'=>''],'reason'=>''];
        return [$service,$mapper,['hash'=>str_repeat('a',64),'members'=>[$member],'indexActions'=>[],'note'=>'','idempotencyKey'=>'request-1']];
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
    }}
