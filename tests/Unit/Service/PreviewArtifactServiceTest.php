<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;

use OCA\DuplicateFinder\Db\PreviewArtifactMapper;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use OCA\DuplicateFinder\Service\EvidenceService;
use OCA\DuplicateFinder\Service\EvidenceSnapshotService;
use OCA\DuplicateFinder\Service\PreviewArtifactService;
use PHPUnit\Framework\TestCase;

class PreviewArtifactServiceTest extends TestCase
{
    private function snapshot(): array { return ['appRef' => 7, 'size' => 1, 'revisionToken' => str_repeat('a', 64)]; }
    private function evidence(): array {
        $snapshot = $this->snapshot();
        return ['id' => 19, 'appRef' => 7, 'record' => ['before' => $snapshot, 'after' => $snapshot,
            'report' => ['status' => 'passed', 'scope' => 'original_all_exposed_frames', 'frames_decoded' => 1]],
            'checkerStatus' => 'current', 'usability' => 'unverifiable', 'reason' => 'native_revision_not_rechecked'];
    }
    private function descriptor(): array {
        // Synthetic JPEG SOF header fixture: getimagesize validates dimensions and format.
        $jpeg = hex2bin('ffd8ffc00011080001000103011100021100031100ffd9');
        return ['status' => 'available', 'scope' => 'original_first_frame_scaled', 'mime' => 'image/jpeg',
            'frameIndex' => 0, 'width' => 1, 'height' => 1, 'imageBase64' => base64_encode($jpeg)];
    }
    private function service($mapper, ?array $evidence = null, ?array $snapshot = null): PreviewArtifactService {
        $findings = $this->createMock(EvidenceService::class);
        $findings->method('getEvidence')->with(19, 7)->willReturn($evidence ?? $this->evidence());
        $snapshots = $this->createMock(EvidenceSnapshotService::class);
        $snapshots->method('snapshot')->with(7)->willReturn($snapshot ?? $this->snapshot());
        return new PreviewArtifactService($mapper, $findings, $snapshots);
    }
    public function testStoresBoundHistoricalArtifactAndExactRetry(): void {
        $mapper = $this->createMock(PreviewArtifactMapper::class);
        $stored = null;
        $mapper->method('find')->willReturnCallback(static function () use (&$stored) { return $stored; });
        $mapper->expects($this->once())->method('append')->willReturnCallback(function ($ref, $id, $at, $json) use (&$stored) {
            $this->assertSame(7, $ref); $this->assertSame(19, $id);
            $stored = ['id' => 31, 'created_at' => $at, 'record_json' => $json]; return 31;
        });
        $service = $this->service($mapper);
        $first = $service->store(7, 19, str_repeat('a', 64), $this->descriptor());
        $this->assertSame($first, $service->store(7, 19, str_repeat('a', 64), $this->descriptor()));
        $this->assertSame($this->snapshot(), $first['record']['boundSnapshot']);
        $this->assertSame($this->descriptor(), $first['record']['descriptor']);
        $this->assertSame('not_rechecked', $first['nativeFreshness']);
        $this->assertSame('unverifiable', $first['usability']);
        $this->assertSame('not_provided', $first['visualAssessment']);
    }
    public function testRejectsInvalidDescriptorsWithoutWriting(): void {
        $bad = [['mime' => 'image/svg+xml'], ['frameIndex' => 1], ['width' => 513], ['height' => 0],
            ['width' => 2], ['status' => 'missing'], ['scope' => 'cache'], ['path' => '/arbitrary'],
            ['imageBase64' => '!!!!'], ['imageBase64' => base64_encode('not jpeg')],
            ['imageBase64' => base64_encode(str_repeat('x', 65537))], ['width' => '1']];
        foreach ($bad as $change) {
            $mapper = $this->createMock(PreviewArtifactMapper::class);
            $mapper->expects($this->never())->method('append');
            try { $this->service($mapper)->store(7, 19, str_repeat('a', 64), array_merge($this->descriptor(), $change)); $this->fail('Accepted malformed descriptor'); }
            catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
    }
    public function testRejectsUnqualifiedEvidenceAndChangedBinding(): void {
        foreach (['status', 'scope', 'frames', 'checker', 'before', 'after', 'token', 'current'] as $case) {
            $evidence = $this->evidence(); $snapshot = $this->snapshot(); $token = str_repeat('a', 64);
            if ($case === 'status') $evidence['record']['report']['status'] = 'corrupt';
            if ($case === 'scope') $evidence['record']['report']['scope'] = 'header';
            if ($case === 'frames') $evidence['record']['report']['frames_decoded'] = 0;
            if ($case === 'checker') $evidence['checkerStatus'] = 'checker_outdated';
            if ($case === 'before') $evidence['record']['before']['size'] = 2;
            if ($case === 'after') $evidence['record']['after']['size'] = 2;
            if ($case === 'token') $token = str_repeat('b', 64);
            if ($case === 'current') $snapshot['size'] = 2;
            $mapper = $this->createMock(PreviewArtifactMapper::class); $mapper->expects($this->never())->method('append');
            try { $this->service($mapper, $evidence, $snapshot)->store(7, 19, $token, $this->descriptor()); $this->fail('Accepted invalid binding ' . $case); }
            catch (EvidenceConflictException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
    }
    public function testHistoricalImageRemainsReadableWithStaleAndUnavailableEvidence(): void {
        $record = ['schemaVersion' => 1, 'boundSnapshot' => $this->snapshot(), 'descriptor' => $this->descriptor(), 'sha256' => hash('sha256', base64_decode($this->descriptor()['imageBase64']))];
        foreach (['stale', 'unverifiable', 'checker_outdated', 'current'] as $status) {
            $mapper = $this->createMock(PreviewArtifactMapper::class);
            $mapper->method('find')->willReturn(['id' => 31, 'created_at' => 100, 'record_json' => json_encode($record)]);
            $evidence = $this->evidence(); $evidence['usability'] = $status;
            $result = $this->service($mapper, $evidence)->getPreview(7, 19);
            $this->assertSame($record, $result['record']); $this->assertSame($status === 'current' ? 'unverifiable' : $status, $result['usability']);
            $this->assertSame('not_rechecked', $result['nativeFreshness']);
        }
    }
    public function testMissingArtifactReturnsNull(): void {
        $mapper = $this->createMock(PreviewArtifactMapper::class); $mapper->method('find')->willReturn(null);
        $this->assertNull($this->service($mapper)->getPreview(7, 19));
    }
    public function testDifferentExistingArtifactConflicts(): void {
        $mapper = $this->createMock(PreviewArtifactMapper::class);
        $mapper->method('find')->willReturn(['id' => 31, 'created_at' => 100, 'record_json' => '{}']);
        $mapper->expects($this->never())->method('append');
        $this->expectException(EvidenceConflictException::class);
        $this->service($mapper)->store(7, 19, str_repeat('a', 64), $this->descriptor());
    }
    public function testConcurrentUniqueRetryReturnsOnlyTheExactPersistedArtifact(): void
    {
        foreach ([false, true] as $different) {
            $mapper = $this->createMock(PreviewArtifactMapper::class);
            $raceRow = null;
            $mapper->method('find')->willReturnCallback(static function () use (&$raceRow) { return $raceRow; });
            $exception = $this->getMockBuilder(\OCP\DB\Exception::class)->disableOriginalConstructor()->onlyMethods(['getReason'])->getMock();
            $exception->method('getReason')->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
            $mapper->method('append')->willReturnCallback(function ($ref, $id, $at, $json) use (&$raceRow, $exception, $different) {
                $record = json_decode($json, true);
                if ($different) $record['descriptor']['imageBase64'] = 'different';
                $raceRow = ['id' => 32, 'created_at' => 99, 'record_json' => json_encode($record)];
                throw $exception;
            });
            try {
                $result = $this->service($mapper)->store(7, 19, str_repeat('a', 64), $this->descriptor());
                $this->assertFalse($different, 'Accepted different concurrent image');
                $this->assertSame(32, $result['id']); $this->assertSame(99, $result['createdAt']);
            } catch (EvidenceConflictException $e) {
                $this->assertTrue($different, 'Rejected identical concurrent retry');
            }
        }
    }}
