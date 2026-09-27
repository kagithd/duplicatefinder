<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;

use OCA\DuplicateFinder\Db\DetailArtifactMapper;
use OCA\DuplicateFinder\Service\DetailArtifactService;
use OCA\DuplicateFinder\Service\EvidenceService;
use OCA\DuplicateFinder\Service\EvidenceSnapshotService;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use PHPUnit\Framework\TestCase;

class DetailArtifactServiceTest extends TestCase
{
    private function snapshot(): array { return ['appRef' => 7, 'size' => 42, 'revisionToken' => str_repeat('a', 64)]; }
    private function finding(): array {
        return ['id' => 19, 'appRef' => 7, 'checkerStatus' => 'current', 'usability' => 'current',
            'record' => ['before' => $this->snapshot(), 'after' => $this->snapshot(),
                'report' => ['status' => 'passed', 'scope' => 'original_all_exposed_frames', 'frames_decoded' => 2]]];
    }
    private function descriptor(): array {
        // A real synthetic 1x1 RGB PNG, not an arbitrary browser-provided source.
        $png = "\x89PNG\r\n\x1a\n";
        foreach (['IHDR' => pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0), 'IDAT' => gzcompress("\0\xff\0\0"), 'IEND' => ''] as $type => $data) {
            $png .= pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        }
        return ['status' => 'available', 'scope' => 'original_selected_frame_region', 'mime' => 'image/png',
            'frameIndex' => 1, 'sourceWidth' => 1000, 'sourceHeight' => 800,
            'region' => ['x' => 900, 'y' => 700, 'width' => 1, 'height' => 1],
            'width' => 1, 'height' => 1, 'rasterMode' => 'RGB', 'imageBase64' => base64_encode($png)];
    }
    private function service($mapper, ?array $finding = null, ?array $snapshot = null): DetailArtifactService {
        $evidence = $this->createMock(EvidenceService::class);
        $evidence->method('getEvidence')->with(19, 7)->willReturn($finding ?? $this->finding());
        $snapshots = $this->createMock(EvidenceSnapshotService::class);
        $snapshots->method('snapshot')->with(7)->willReturn($snapshot ?? $this->snapshot());
        return new DetailArtifactService($mapper, $evidence, $snapshots);
    }
    public function testStoresExactRetryAndDistinctRegionsWithoutClaimingFreshness(): void {
        $mapper = $this->createMock(DetailArtifactMapper::class);
        $rows = [];
        $mapper->method('find')->willReturnCallback(static function ($ref, $evidence, $key) use (&$rows) { return $rows[$key] ?? null; });
        $mapper->expects($this->exactly(2))->method('append')->willReturnCallback(function ($ref, $evidence, $key, $at, $json) use (&$rows) {
            $this->assertSame(7, $ref); $this->assertSame(19, $evidence);
            $id = count($rows) + 1;
            $rows[$key] = ['id' => $id, 'created_at' => $at, 'record_json' => $json];
            return $id;
        });
        $service = $this->service($mapper);
        $first = $service->store(7, 19, str_repeat('a', 64), $this->descriptor());
        $retry = $this->descriptor();
        $retry['region'] = array_reverse($retry['region'], true);
        $this->assertSame($first, $service->store(7, 19, str_repeat('a', 64), $retry));
        $this->assertSame('unverifiable', $first['usability']);
        $this->assertSame('not_rechecked', $first['nativeFreshness']);
        $this->assertSame('original_selected_frame_region', $first['validityScope']);
        $this->assertSame($this->snapshot(), $first['record']['boundSnapshot']);
        $this->assertSame($this->descriptor(), $first['record']['descriptor']);
        $next = $this->descriptor(); $next['region']['x'] = 901;
        $this->assertNotSame($first['id'], $service->store(7, 19, str_repeat('a', 64), $next)['id']);
    }
    public function testRejectsInvalidDescriptorBeforeWriting(): void {
        $bad = [['frameIndex' => -1], ['frameIndex' => true], ['mime' => 'image/svg+xml'], ['width' => 513],
            ['sourceWidth' => 900], ['sourceHeight' => '800'], ['region' => ['x' => -1, 'y' => 0, 'width' => 1, 'height' => 1]],
            ['region' => ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 1]],
            ['path' => '/arbitrary'], ['rasterMode' => 'RGBA'], ['imageBase64' => 'bad'],
            ['imageBase64' => base64_encode(str_repeat('x', 2097153))], ['scope' => 'original_first_frame_scaled']];
        foreach ($bad as $change) {
            $mapper = $this->createMock(DetailArtifactMapper::class); $mapper->expects($this->never())->method('append');
            try { $this->service($mapper)->store(7, 19, str_repeat('a', 64), array_replace($this->descriptor(), $change)); $this->fail('Malformed detail accepted'); }
            catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
    }
    public function testRequiresMatchingPassedFindingAndFrame(): void {
        foreach (['frame', 'checker', 'status', 'scope', 'before', 'after', 'current', 'token'] as $case) {
            $finding = $this->finding(); $snapshot = $this->snapshot(); $token = str_repeat('a', 64);
            if ($case === 'frame') $finding['record']['report']['frames_decoded'] = 1;
            if ($case === 'checker') $finding['checkerStatus'] = 'outdated';
            if ($case === 'status') $finding['record']['report']['status'] = 'corrupt';
            if ($case === 'scope') $finding['record']['report']['scope'] = 'header';
            if ($case === 'before' || $case === 'after') $finding['record'][$case]['size'] = 43;
            if ($case === 'current') $snapshot['size'] = 43;
            if ($case === 'token') $token = str_repeat('b', 64);
            $mapper = $this->createMock(DetailArtifactMapper::class); $mapper->expects($this->never())->method('append');
            try { $this->service($mapper, $finding, $snapshot)->store(7, 19, $token, $this->descriptor()); $this->fail('Invalid binding accepted: ' . $case); }
            catch (EvidenceConflictException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
    }
    public function testSameSelectionCannotReplaceDifferentPixels(): void {
        $mapper = $this->createMock(DetailArtifactMapper::class);
        $record = ['schemaVersion' => 1, 'boundSnapshot' => $this->snapshot(), 'descriptor' => $this->descriptor(), 'sha256' => str_repeat('b', 64)];
        $mapper->method('find')->willReturn(['id' => 1, 'created_at' => 1, 'record_json' => json_encode($record)]);
        $mapper->expects($this->never())->method('append');
        $this->expectException(EvidenceConflictException::class);
        $this->service($mapper)->store(7, 19, str_repeat('a', 64), $this->descriptor());
    }
    public function testReadsOnlyScopedIdAndHistoricalStatus(): void {
        $mapper = $this->createMock(DetailArtifactMapper::class);
        $mapper->expects($this->once())->method('findById')->with(7, 19, 31)->willReturn(null);
        $this->assertNull($this->service($mapper)->getDetail(7, 19, 31));
    }
}