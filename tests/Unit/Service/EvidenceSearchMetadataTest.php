<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;
use OCA\DuplicateFinder\Service\EvidenceSearchMetadata;
use PHPUnit\Framework\TestCase;
class EvidenceSearchMetadataTest extends TestCase {
    public function testExtractsHistoricalMetadataWithoutChangingTheReport(): void {
        foreach (['passed','corrupt','unsupported','inaccessible','limit','stale','error'] as $status) {
            $json=json_encode(['schemaVersion'=>1,'report'=>['status'=>$status,'format'=>'PNG']],JSON_THROW_ON_ERROR);
            $this->assertSame(['status'=>$status,'format'=>'PNG'],EvidenceSearchMetadata::fromJson($json));
        }
        $this->assertSame(['status'=>'unsupported','format'=>null],EvidenceSearchMetadata::fromJson('{"schemaVersion":1,"report":{"status":"unsupported","format":null}}'));
    }
    public function testMalformedOrUnknownReportsRemainExplicitlyInvalid(): void {
        foreach (['broken','null','[]','{"report":{"status":"passed"}}','{"schemaVersion":2,"report":{"status":"passed","format":"PNG"}}','{"schemaVersion":1,"report":{"status":"healthy","format":"PNG"}}','{"schemaVersion":1,"report":{"status":"passed","format":[]}}'] as $json) {
            $this->assertSame(['status'=>'invalid','format'=>null],EvidenceSearchMetadata::fromJson($json));
        }
        $this->assertSame(['status'=>'invalid','format'=>null],EvidenceSearchMetadata::fromJson(json_encode(['schemaVersion'=>1,'report'=>['status'=>'passed','format'=>str_repeat('x',129)]])));
    }
    public function testNewEvidenceWritesSearchLabelsAlongsideUnchangedJson(): void {
        $json='{"schemaVersion":1,"report":{"status":"corrupt","format":"JPEG"}}';
        $db=$this->createMock(\OCP\IDBConnection::class);
        $qb=$this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('createNamedParameter')->willReturnCallback(static fn($value)=>$value);
        $qb->method('insert')->willReturnSelf();
        $qb->expects($this->once())->method('values')->with($this->callback(static fn($v)=>
            ($v['search_status']??null)==='corrupt' && ($v['search_format']??null)==='JPEG' && $v['record_json']===$json
        ))->willReturnSelf();
        $qb->method('executeStatement')->willReturn(1);
        $qb->method('getLastInsertId')->willReturn(7);
        $this->assertSame(7,(new \OCA\DuplicateFinder\Db\EvidenceMapper($db))->append(2,123,$json));
    }
}