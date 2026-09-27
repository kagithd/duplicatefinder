<?php
namespace OCA\DuplicateFinder\Service;

use OCA\DuplicateFinder\Db\PreviewArtifactMapper;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;

/** Only a trusted in-process adapter may append original decoder artifacts. */
class PreviewArtifactService
{
    private PreviewArtifactMapper $mapper;
    private EvidenceService $evidence;
    private EvidenceSnapshotService $snapshots;

    public function __construct(PreviewArtifactMapper $mapper, EvidenceService $evidence, EvidenceSnapshotService $snapshots)
    {
        $this->mapper = $mapper;
        $this->evidence = $evidence;
        $this->snapshots = $snapshots;
    }

    public function store(int $appRef, int $evidenceId, string $expectedToken, array $descriptor): array
    {
        $this->validateIds($appRef, $evidenceId);
        if (!preg_match('/\A[a-f0-9]{64}\z/', $expectedToken)) {
            throw new \InvalidArgumentException('Invalid revision token');
        }
        $image = $this->validateDescriptor($descriptor);
        $finding = $this->evidence->getEvidence($evidenceId, $appRef);
        $report = $finding['record']['report'] ?? [];
        if ($finding === null || ($finding['checkerStatus'] ?? null) !== 'current' ||
            ($report['status'] ?? null) !== 'passed' || ($report['scope'] ?? null) !== 'original_all_exposed_frames' ||
            !is_int($report['frames_decoded'] ?? null) || $report['frames_decoded'] < 1) {
            throw new EvidenceConflictException('A complete original finding from the current checker is required');
        }
        try {
            $current = $this->snapshots->snapshot($appRef);
        } catch (\RuntimeException $e) {
            throw new EvidenceConflictException('Current original cannot be verified', 0, $e);
        }
        if (!hash_equals($current['revisionToken'], $expectedToken) ||
            !$this->sameFields($current, $finding['record']['before']) ||
            !$this->sameFields($current, $finding['record']['after'])) {
            throw new EvidenceConflictException('Original identity or revision changed');
        }
        $record = ['schemaVersion' => 1, 'boundSnapshot' => $current,
            'descriptor' => $descriptor, 'sha256' => hash('sha256', $image)];
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        if (strlen($json) > 131072) {
            throw new \InvalidArgumentException('Preview artifact record is too large');
        }
        $row = $this->mapper->find($appRef, $evidenceId);
        if ($row !== null) {
            $this->assertSameRecord($row, $record);
            return $this->response($appRef, $evidenceId, $row, $finding);
        }
        $createdAt = time();
        try {
            $id = $this->mapper->append($appRef, $evidenceId, $createdAt, $json);
            $row = ['id' => $id, 'created_at' => $createdAt, 'record_json' => $json];
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            $row = $this->mapper->find($appRef, $evidenceId);
            if ($row === null) {
                throw new EvidenceConflictException('Concurrent preview binding conflict', 0, $e);
            }
            $this->assertSameRecord($row, $record);
        }
        return $this->response($appRef, $evidenceId, $row, $finding);
    }

    public function getPreview(int $appRef, int $evidenceId): ?array
    {
        $this->validateIds($appRef, $evidenceId);
        $row = $this->mapper->find($appRef, $evidenceId);
        if ($row === null) {
            return null;
        }
        return $this->response($appRef, $evidenceId, $row, $this->evidence->getEvidence($evidenceId, $appRef));
    }

    private function response(int $appRef, int $evidenceId, array $row, ?array $finding): array
    {
        $usability = $finding['usability'] ?? 'unverifiable';
        $reason = $finding['reason'] ?? 'evidence_unavailable';
        if ($usability === 'current') {
            $usability = 'unverifiable';
            $reason = 'native_revision_not_rechecked';
        }
        return ['id' => (int)$row['id'], 'appRef' => $appRef, 'evidenceId' => $evidenceId,
            'createdAt' => (int)$row['created_at'], 'record' => json_decode($row['record_json'], true, 512, JSON_THROW_ON_ERROR),
            'usability' => $usability,
            'reason' => $reason,
            'checkerStatus' => $finding['checkerStatus'] ?? 'unverifiable',
            'nativeFreshness' => 'not_rechecked', 'validityScope' => 'original_first_frame_scaled',
            'visualAssessment' => 'not_provided'];
    }

    private function validateIds(int $appRef, int $evidenceId): void
    {
        if ($appRef < 1 || $evidenceId < 1 || $evidenceId === PHP_INT_MAX) {
            throw new \InvalidArgumentException('Invalid preview reference');
        }
    }

    private function validateDescriptor(array $descriptor): string
    {
        $fields = ['status', 'scope', 'mime', 'frameIndex', 'width', 'height', 'imageBase64'];
        if (count($descriptor) !== count($fields) || array_diff($fields, array_keys($descriptor)) !== [] ||
            ($descriptor['status'] ?? null) !== 'available' || ($descriptor['scope'] ?? null) !== 'original_first_frame_scaled' ||
            ($descriptor['mime'] ?? null) !== 'image/jpeg' || ($descriptor['frameIndex'] ?? null) !== 0) {
            throw new \InvalidArgumentException('Invalid preview descriptor or scope');
        }
        foreach (['width', 'height'] as $field) {
            if (!is_int($descriptor[$field]) || $descriptor[$field] < 1 || $descriptor[$field] > 512) {
                throw new \InvalidArgumentException('Preview dimensions must be bounded positive integers');
            }
        }
        $base64 = $descriptor['imageBase64'];
        if (!is_string($base64) || strlen($base64) > 87384) {
            throw new \InvalidArgumentException('Preview image is too large');
        }
        $image = base64_decode($base64, true);
        if ($image === false || $image === '' || strlen($image) > 65536 || base64_encode($image) !== $base64) {
            throw new \InvalidArgumentException('Invalid bounded base64 preview');
        }
        $size = @getimagesizefromstring($image);
        if ($size === false || $size[2] !== IMAGETYPE_JPEG || $size[0] !== $descriptor['width'] || $size[1] !== $descriptor['height']) {
            throw new \InvalidArgumentException('Preview JPEG header or dimensions disagree');
        }
        return $image;
    }

    private function sameFields(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) return false;
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || $actual[$key] !== $value) return false;
        }
        return true;
    }

    private function assertSameRecord(array $row, array $record): void
    {
        $stored = json_decode($row['record_json'], true, 512, JSON_THROW_ON_ERROR);
        // Descriptor field order is irrelevant; types and every bound value are exact.
        if (!$this->sameFields($stored['boundSnapshot'] ?? [], $record['boundSnapshot']) ||
            !$this->sameFields($stored['descriptor'] ?? [], $record['descriptor']) ||
            ($stored['schemaVersion'] ?? null) !== 1 || ($stored['sha256'] ?? null) !== $record['sha256']) {
            throw new EvidenceConflictException('Evidence already has a different immutable preview');
        }
    }
}
