<?php
namespace OCA\DuplicateFinder\Service;

use OCA\DuplicateFinder\Db\DetailArtifactMapper;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;

/** Only a trusted in-process adapter may append original decoder artifacts. */
class DetailArtifactService
{
    private DetailArtifactMapper $mapper;
    private EvidenceService $evidence;
    private EvidenceSnapshotService $snapshots;

    public function __construct(DetailArtifactMapper $mapper, EvidenceService $evidence, EvidenceSnapshotService $snapshots)
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
        $region = $descriptor['region'];
        $descriptor['region'] = ['x' => $region['x'], 'y' => $region['y'], 'width' => $region['width'], 'height' => $region['height']];
        $selection = hash('sha256', json_encode([$descriptor['frameIndex'], $region['x'], $region['y'], $region['width'], $region['height']], JSON_THROW_ON_ERROR));
        $finding = $this->evidence->getEvidence($evidenceId, $appRef);
        $report = $finding['record']['report'] ?? [];
        if ($finding === null || ($finding['checkerStatus'] ?? null) !== 'current' ||
            ($report['status'] ?? null) !== 'passed' || ($report['scope'] ?? null) !== 'original_all_exposed_frames' ||
            !is_int($report['frames_decoded'] ?? null) || $report['frames_decoded'] <= $descriptor['frameIndex']) {
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
        if (strlen($json) > 3 * 1024 * 1024) {
            throw new \InvalidArgumentException('Detail artifact record is too large');
        }
        $row = $this->mapper->find($appRef, $evidenceId, $selection);
        if ($row !== null) {
            $this->assertSameRecord($row, $record);
            return $this->response($appRef, $evidenceId, $row, $finding);
        }
        $createdAt = time();
        try {
            $id = $this->mapper->append($appRef, $evidenceId, $selection, $createdAt, $json);
            $row = ['id' => $id, 'created_at' => $createdAt, 'record_json' => $json];
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            $row = $this->mapper->find($appRef, $evidenceId, $selection);
            if ($row === null) {
                throw new EvidenceConflictException('Concurrent detail binding conflict', 0, $e);
            }
            $this->assertSameRecord($row, $record);
        }
        return $this->response($appRef, $evidenceId, $row, $finding);
    }

    public function getDetail(int $appRef, int $evidenceId, int $detailId): ?array
    {
        $this->validateIds($appRef, $evidenceId);
        if ($detailId < 1 || $detailId === PHP_INT_MAX) throw new \InvalidArgumentException('Invalid detail ID');
        $row = $this->mapper->findById($appRef, $evidenceId, $detailId);
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
            'nativeFreshness' => 'not_rechecked', 'validityScope' => 'original_selected_frame_region',
            'visualAssessment' => 'not_provided'];
    }

    private function validateIds(int $appRef, int $evidenceId): void
    {
        if ($appRef < 1 || $evidenceId < 1 || $evidenceId === PHP_INT_MAX) {
            throw new \InvalidArgumentException('Invalid detail reference');
        }
    }

    private function validateDescriptor(array $descriptor): string
    {
        $fields = ['status', 'scope', 'mime', 'frameIndex', 'sourceWidth', 'sourceHeight', 'region', 'width', 'height', 'rasterMode', 'imageBase64'];
        if (count($descriptor) !== count($fields) || array_diff($fields, array_keys($descriptor)) !== [] ||
            $descriptor['status'] !== 'available' || $descriptor['scope'] !== 'original_selected_frame_region' ||
            $descriptor['mime'] !== 'image/png' || !in_array($descriptor['rasterMode'], ['RGB', 'RGBA'], true)) {
            throw new \InvalidArgumentException('Invalid detail descriptor or scope');
        }
        foreach (['frameIndex', 'sourceWidth', 'sourceHeight', 'width', 'height'] as $field) {
            $max = in_array($field, ['width', 'height'], true) ? 512 : 2147483647;
            if (!is_int($descriptor[$field]) || $descriptor[$field] < ($field === 'frameIndex' ? 0 : 1) || $descriptor[$field] > $max) {
                throw new \InvalidArgumentException('Invalid bounded detail dimensions or frame');
            }
        }
        $region = $descriptor['region'];
        if (!is_array($region) || count($region) !== 4 || array_diff(['x', 'y', 'width', 'height'], array_keys($region)) !== []) {
            throw new \InvalidArgumentException('Invalid detail region');
        }
        foreach (['x', 'y', 'width', 'height'] as $field) {
            if (!is_int($region[$field]) || $region[$field] < 0 || $region[$field] > 2147483647) {
                throw new \InvalidArgumentException('Invalid region coordinate');
            }
        }
        if ($region['width'] !== $descriptor['width'] || $region['height'] !== $descriptor['height'] ||
            $region['x'] > $descriptor['sourceWidth'] - $region['width'] ||
            $region['y'] > $descriptor['sourceHeight'] - $region['height']) {
            throw new \InvalidArgumentException('Region is outside the oriented source or has mismatched dimensions');
        }
        $base64 = $descriptor['imageBase64'];
        if (!is_string($base64) || strlen($base64) > 2796204) {
            throw new \InvalidArgumentException('Detail image is too large');
        }
        $image = base64_decode($base64, true);
        if ($image === false || strlen($image) < 33 || strlen($image) > 2097152 || base64_encode($image) !== $base64) {
            throw new \InvalidArgumentException('Invalid bounded detail base64');
        }
        // Trusted decoder produced the PNG; this validates its envelope, not image integrity.
        $size = @getimagesizefromstring($image);
        if ($size === false || $size[2] !== IMAGETYPE_PNG || $size[0] !== $descriptor['width'] || $size[1] !== $descriptor['height'] ||
            substr($image, 12, 4) !== 'IHDR' || ord($image[24]) !== 8 || ord($image[25]) !== ($descriptor['rasterMode'] === 'RGB' ? 2 : 6)) {
            throw new \InvalidArgumentException('Detail PNG header, raster mode or dimensions disagree');
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
            throw new EvidenceConflictException('Evidence already has a different immutable detail');
        }
    }
}
