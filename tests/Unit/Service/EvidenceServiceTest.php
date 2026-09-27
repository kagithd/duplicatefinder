<?php

namespace OCA\DuplicateFinder\Tests\Unit\Service;

use OCA\DuplicateFinder\Db\EvidenceMapper;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use OCA\DuplicateFinder\Service\EvidenceCheckerPolicy;
use OCA\DuplicateFinder\Service\EvidenceService;
use OCA\DuplicateFinder\Service\EvidenceSnapshotService;
use PHPUnit\Framework\TestCase;

class EvidenceServiceTest extends TestCase
{
    private function snapshot(): array
    {
        return ['appRef' => 7, 'indexOwner' => 'alice', 'indexPath' => '/alice/files/a.png',
            'owner' => 'alice', 'path' => '/alice/files/a.png', 'nodeId' => 42, 'storageId' => 'home::alice',
            'etag' => 'rev-1', 'size' => 123, 'mtime' => 1000, 'revisionToken' => str_repeat('a', 64)];
    }

    private function report(): array
    {
        $native = ['dev' => '1', 'ino' => '2', 'size' => '123', 'mtime_ns' => '1727000000123456789', 'ctime_ns' => '1727000000987654321', 'regular' => true];
        return ['status' => 'passed', 'reason' => 'all_frames_decoded', 'checker_version' => '1',
            'checker' => ['id' => 'file_review.image', 'version' => '1', 'ruleVersion' => '1'],
            'scope' => 'original_all_exposed_frames', 'content_judgment' => 'not_assessed',
            'format' => 'PNG', 'frames_decoded' => 1, 'pixels_decoded' => 1,
            'decoder' => ['name' => 'Pillow', 'version' => '11'],
            'revision_before' => $native, 'revision_after' => $native, 'path_revision_after' => $native,
            'limits' => ['max_pixels' => 1000]];
    }

    public function testTrustedRecordKeepsExactNanosecondsAndBothSnapshots(): void
    {
        $snapshot = $this->snapshot();
        $resolver = $this->createMock(EvidenceSnapshotService::class);
        $resolver->expects($this->once())->method('snapshot')->with(7)->willReturn($snapshot);
        $mapper = $this->createMock(EvidenceMapper::class);
        $mapper->expects($this->once())->method('append')->willReturnCallback(function (int $appRef, int $createdAt, string $json): int {
            $record = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(7, $appRef);
            $this->assertGreaterThan(0, $createdAt);
            $this->assertSame('1727000000123456789', $record['report']['revision_before']['mtime_ns']);
            $this->assertSame('1727000000987654321', $record['report']['revision_after']['ctime_ns']);
            $this->assertSame($this->snapshot(), $record['before']);
            $this->assertSame($this->snapshot(), $record['after']);
            return 19;
        });
        $service = new EvidenceService($mapper, $resolver, $this->createMock(EvidenceCheckerPolicy::class));
        $this->assertSame(19, $service->recordEvidence(7, $snapshot['revisionToken'], $snapshot, $snapshot, $this->report()));
    }

    public function testCurrentServerRevisionConflictRejectsWithoutPersisting(): void
    {
        foreach (['indexOwner' => 'other', 'indexPath' => '/alice/files/b.png', 'nodeId' => 43, 'storageId' => 'other', 'etag' => 'changed', 'size' => 124, 'mtime' => 1001, 'revisionToken' => str_repeat('b', 64)] as $field => $value) {
            $old = $this->snapshot();
            $current = array_merge($old, [$field => $value]);
            $resolver = $this->createMock(EvidenceSnapshotService::class);
            $resolver->method('snapshot')->willReturn($current);
            $mapper = $this->createMock(EvidenceMapper::class);
            $mapper->expects($this->never())->method('append');
            $service = new EvidenceService($mapper, $resolver, $this->createMock(EvidenceCheckerPolicy::class));
            try {
                $service->recordEvidence(7, $old['revisionToken'], $old, $old, $this->report());
                $this->fail('Accepted conflicting field ' . $field);
            } catch (EvidenceConflictException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testDecoderRevisionChangeAndRoundedNanosecondsAreRejected(): void
    {
        foreach (['changed', 'rounded', 'unqualified', 'path_changed', 'non_regular'] as $case) {
            $snapshot = $this->snapshot();
            $report = $this->report();
            if ($case === 'changed') $report['revision_after']['ino'] = '3';
            if ($case === 'rounded') $report['revision_after']['mtime_ns'] = 1727000000123456789;
            if ($case === 'path_changed') $report['path_revision_after']['ino'] = '3';
            if ($case === 'non_regular') $report['path_revision_after']['regular'] = false;
            if ($case === 'unqualified') $report['scope'] = 'header_only';
            $resolver = $this->createMock(EvidenceSnapshotService::class);
            $resolver->method('snapshot')->willReturn($snapshot);
            $mapper = $this->createMock(EvidenceMapper::class);
            $mapper->expects($this->never())->method('append');
            $service = new EvidenceService($mapper, $resolver, $this->createMock(EvidenceCheckerPolicy::class));
            try {
                $service->recordEvidence(7, $snapshot['revisionToken'], $snapshot, $snapshot, $report);
                $this->fail('Accepted invalid report ' . $case);
            } catch (\InvalidArgumentException | EvidenceConflictException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testHistoryIsBoundedAndUsabilityDoesNotMutateHistoricalRecord(): void
    {
        $snapshot = $this->snapshot();
        $record = ['schemaVersion' => 1, 'before' => $snapshot, 'after' => $snapshot, 'report' => $this->report()];
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        foreach (['current', 'stale', 'unverifiable', 'checker_outdated'] as $status) {
            $mapper = $this->createMock(EvidenceMapper::class);
            $mapper->expects($this->once())->method('history')->with(7, 0, 2)->willReturn([
                ['id' => 10, 'created_at' => 1000, 'record_json' => $json],
                ['id' => 9, 'created_at' => 999, 'record_json' => $json],
            ]);
            $resolver = $this->createMock(EvidenceSnapshotService::class);
            if ($status === 'unverifiable') {
                $resolver->method('snapshot')->willThrowException(new \RuntimeException('unavailable'));
            } else {
                $resolver->method('snapshot')->willReturn($status === 'stale' ? array_merge($snapshot, ['etag' => 'rev-2']) : $snapshot);
            }
            $policy = $this->createMock(EvidenceCheckerPolicy::class);
            $policy->method('evaluate')->willReturn($status === 'checker_outdated' ? 'checker_outdated' : 'current');
            $page = (new EvidenceService($mapper, $resolver, $policy))->getHistory(7, 0, 1);
            $this->assertCount(1, $page['items']);
            $this->assertSame(10, $page['nextCursor']);
            $this->assertSame($record, $page['items'][0]['record']);
            $this->assertSame($status === 'current' ? 'unverifiable' : $status, $page['items'][0]['usability']);
            $this->assertSame('not_rechecked', $page['items'][0]['nativeFreshness']);
            $this->assertSame('nextcloud_metadata_only', $page['items'][0]['validityScope']);
            if ($status === 'current') $this->assertSame('native_revision_not_rechecked', $page['items'][0]['reason']);
            $this->assertSame($json, json_encode($page['items'][0]['record'], JSON_THROW_ON_ERROR));
        }
    }
    public function testImportRejectsWrongExpectedTokenAndDisagreeingBeforeAfterWithoutWriting(): void
    {
        foreach (['token', 'before', 'after', 'missing'] as $case) {
            $snapshot = $this->snapshot();
            $before = $snapshot;
            $after = $snapshot;
            $token = $snapshot['revisionToken'];
            if ($case === 'token') $token = str_repeat('b', 64);
            if ($case === 'before') $before['nodeId'] = 43;
            if ($case === 'after') $after['etag'] = 'changed';
            $resolver = $this->createMock(EvidenceSnapshotService::class);
            if ($case === 'missing') $resolver->method('snapshot')->willThrowException(new \RuntimeException('removed'));
            else $resolver->method('snapshot')->willReturn($snapshot);
            $mapper = $this->createMock(EvidenceMapper::class);
            $mapper->expects($this->never())->method('append');
            $service = new EvidenceService($mapper, $resolver, $this->createMock(EvidenceCheckerPolicy::class));
            try {
                $service->recordEvidence(7, $token, $before, $after, $this->report());
                $this->fail('Accepted conflicting import ' . $case);
            } catch (EvidenceConflictException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testDiagnosticNonPassedReportRetainsUnavailableNativeRevision(): void
    {
        $snapshot = $this->snapshot();
        $report = $this->report();
        $report['status'] = 'inaccessible';
        $report['reason'] = 'open_errno_13';
        $report['revision_before'] = null;
        $report['revision_after'] = null;
        $report['path_revision_after'] = null;
        $report['decoder'] = null;
        $report['format'] = null;
        $report['frames_decoded'] = 0;
        $report['pixels_decoded'] = 0;
        $resolver = $this->createMock(EvidenceSnapshotService::class);
        $resolver->method('snapshot')->willReturn($snapshot);
        $mapper = $this->createMock(EvidenceMapper::class);
        $mapper->method('append')->willReturnCallback(function ($id, $time, $json) use ($report) {
            $this->assertSame($report, json_decode($json, true)['report']);
            return 20;
        });
        $service = new EvidenceService($mapper, $resolver, $this->createMock(EvidenceCheckerPolicy::class));
        $this->assertSame(20, $service->recordEvidence(7, $snapshot['revisionToken'], $snapshot, $snapshot, $report));
    }
}
