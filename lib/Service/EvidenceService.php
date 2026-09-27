<?php
namespace OCA\DuplicateFinder\Service;

use OCA\DuplicateFinder\Db\EvidenceMapper;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;

/** Only trusted in-process/OCC adapters may record findings. No HTTP import API. */
class EvidenceService
{
    private EvidenceMapper $mapper;
    private EvidenceSnapshotService $snapshots;
    private EvidenceCheckerPolicy $policy;

    public function __construct(EvidenceMapper $mapper, EvidenceSnapshotService $snapshots, EvidenceCheckerPolicy $policy)
    {
        $this->mapper = $mapper;
        $this->snapshots = $snapshots;
        $this->policy = $policy;
    }

    public function recordEvidence(int $appRef, string $expectedToken, array $before, array $after, array $report): int
    {
        if ($appRef < 1 || !preg_match('/\A[a-f0-9]{64}\z/', $expectedToken)) {
            throw new \InvalidArgumentException('Invalid reference or revision token');
        }
        $this->validateReport($report);
        try {
            $current = $this->snapshots->snapshot($appRef);
        } catch (\RuntimeException $e) {
            throw new EvidenceConflictException('Current reference cannot be verified', 0, $e);
        }
        if (!hash_equals($current['revisionToken'], $expectedToken) ||
            !$this->sameFields($current, $before) || !$this->sameFields($current, $after)) {
            throw new EvidenceConflictException('File identity or revision changed');
        }
        if ($report['status'] === 'passed' && $report['revision_before']['size'] !== (string)$current['size']) {
            throw new EvidenceConflictException('Native size does not match the current file');
        }
        // The adapter must bind native observations to this server-resolved
        // original. Metadata comparison is not a file lock or content proof.
        $record = ['schemaVersion' => 1, 'before' => $before, 'after' => $after, 'report' => $report];
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        if (strlen($json) > 131072) throw new \InvalidArgumentException('Evidence record is too large');
        return $this->mapper->append($appRef, time(), $json);
    }

    public function getHistory(int $appRef, int $cursor = 0, int $limit = 25): array
    {
        if ($appRef < 1 || $cursor < 0 || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid history page');
        }
        $rows = $this->mapper->history($appRef, $cursor, $limit + 1);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $current = null;
        if ($rows !== []) {
            try { $current = $this->snapshots->snapshot($appRef); }
            catch (\RuntimeException $e) { /* History remains readable after removal. */ }
        }
        $items = [];
        foreach ($rows as $row) {
            $record = json_decode($row['record_json'], true, 512, JSON_THROW_ON_ERROR);
            $checkerStatus = $this->policy->evaluate($record['report']);
            if ($current === null) {
                $usability = 'unverifiable';
                $reason = 'nextcloud_revision_unavailable';
            } elseif (!$this->sameFields($current, $record['after'])) {
                $usability = 'stale';
                $reason = 'nextcloud_revision_changed';
            } elseif ($checkerStatus === 'checker_outdated') {
                $usability = 'checker_outdated';
                $reason = 'checker_version_changed';
            } elseif ($checkerStatus !== 'current') {
                $usability = 'unverifiable';
                $reason = 'checker_policy_unavailable';
            } else {
                // Native writes may bypass Nextcloud metadata caches. Never
                // promote a historical passed finding to current on this basis.
                $usability = 'unverifiable';
                $reason = 'native_revision_not_rechecked';
            }
            $items[] = ['id' => (int)$row['id'], 'appRef' => $appRef, 'createdAt' => (int)$row['created_at'],
                'record' => $record, 'usability' => $usability, 'reason' => $reason,
                'nativeFreshness' => 'not_rechecked', 'validityScope' => 'nextcloud_metadata_only',
                'checkerStatus' => $checkerStatus];
        }
        return ['items' => $items, 'nextCursor' => $more ? (int)end($rows)['id'] : null];
    }

    public function getEvidence(int $id, int $appRef): ?array
    {
        if ($id < 1 || $id === PHP_INT_MAX || $appRef < 1) return null;
        $page = $this->getHistory($appRef, $id + 1, 1);
        $item = $page['items'][0] ?? null;
        return $item !== null && $item['id'] === $id ? $item : null;
    }

    private function sameFields(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) return false;
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || $actual[$key] !== $value) return false;
        }
        return true;
    }

    private function validateReport(array $report): void
    {
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) throw new \InvalidArgumentException('Decoder report is too large');
        if (!in_array($report['status'] ?? null, ['passed', 'corrupt', 'unsupported', 'inaccessible', 'limit', 'stale', 'error'], true)) {
            throw new \InvalidArgumentException('Unknown decoder status');
        }
        foreach (['reason', 'checker_version', 'scope'] as $field) {
            if (!is_string($report[$field] ?? null) || $report[$field] === '' || strlen($report[$field]) > 1024) {
                throw new \InvalidArgumentException('Missing or invalid decoder field: ' . $field);
            }
        }
        if (($report['content_judgment'] ?? null) !== 'not_assessed' || !is_array($report['limits'] ?? null) || $report['limits'] === []) {
            throw new \InvalidArgumentException('Decoder scope and limits must remain explicit');
        }
        foreach ($report['limits'] as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value <= 0) {
                throw new \InvalidArgumentException('Invalid decoder limit');
            }
        }
        foreach (['id', 'version', 'ruleVersion'] as $field) {
            if (!is_string($report['checker'][$field] ?? null) || $report['checker'][$field] === '' || strlen($report['checker'][$field]) > 128) {
                throw new \InvalidArgumentException('Explicit checker identity required');
            }
        }
        if ($report['checker_version'] !== $report['checker']['version']) throw new \InvalidArgumentException('Conflicting checker versions');
        foreach (['frames_decoded', 'pixels_decoded'] as $field) {
            if (!is_int($report[$field] ?? null) || $report[$field] < 0) throw new \InvalidArgumentException('Invalid decoder count');
        }
        foreach (['revision_before', 'revision_after', 'path_revision_after'] as $field) {
            if (!array_key_exists($field, $report)) throw new \InvalidArgumentException('Missing native revision');
            if ($report[$field] !== null) $this->validateNative($report[$field]);
        }
        if (!array_key_exists('decoder', $report) || !array_key_exists('format', $report)) {
            throw new \InvalidArgumentException('Decoder and format must be explicit');
        }
        if ($report['decoder'] !== null) {
            foreach (['name', 'version'] as $field) {
                if (!is_string($report['decoder'][$field] ?? null) || $report['decoder'][$field] === '' || strlen($report['decoder'][$field]) > 128) {
                    throw new \InvalidArgumentException('Invalid decoder identity');
                }
            }
        }
        if ($report['format'] !== null && (!is_string($report['format']) || $report['format'] === '' || strlen($report['format']) > 128)) {
            throw new \InvalidArgumentException('Invalid format');
        }
        if ($report['status'] === 'passed') {
            if ($report['scope'] !== 'original_all_exposed_frames' || $report['frames_decoded'] < 1 || $report['decoder'] === null || $report['format'] === null) {
                throw new \InvalidArgumentException('Passed requires a qualified complete decode');
            }
            $before = $report['revision_before'];
            $after = $report['revision_after'];
            $path = $report['path_revision_after'];
            if ($before === null || $after === null || $path === null || !$before['regular'] || !$after['regular'] || !$path['regular'] ||
                !$this->sameFields($before, $after) || !$this->sameFields($after, $path)) {
                throw new EvidenceConflictException('Native original changed or is not a regular file');
            }
        }
    }

    private function validateNative($revision): void
    {
        if (!is_array($revision) || count($revision) !== 6 || !is_bool($revision['regular'] ?? null)) {
            throw new \InvalidArgumentException('Invalid native revision');
        }
        foreach (['dev', 'ino', 'size', 'mtime_ns', 'ctime_ns'] as $field) {
            $pattern = in_array($field, ['mtime_ns', 'ctime_ns'], true) ? '/\A-?(?:0|[1-9][0-9]{0,29})\z/' : '/\A(?:0|[1-9][0-9]{0,29})\z/';
            if (!is_string($revision[$field] ?? null) || !preg_match($pattern, $revision[$field])) {
                throw new \InvalidArgumentException('Native identity and nanoseconds must be decimal strings');
            }
        }
    }
}
