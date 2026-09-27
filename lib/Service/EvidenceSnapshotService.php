<?php
namespace OCA\DuplicateFinder\Service;

/** Tokens describe observed metadata, never content hashes or locked snapshots. */
class EvidenceSnapshotService
{
    private ReviewService $review;
    public function __construct(ReviewService $review) { $this->review = $review; }

    public function snapshot(int $appRef): array
    {
        if ($appRef < 1) throw new \InvalidArgumentException('Invalid app reference');
        $reference = $this->review->reference($appRef);
        if ($reference === null || $reference['availability'] !== 'available') {
            throw new \RuntimeException('Current file metadata unavailable');
        }
        $snapshot = ['appRef' => $appRef];
        foreach (['indexOwner', 'indexPath', 'owner', 'path', 'nodeId', 'storageId', 'etag', 'size', 'mtime'] as $field) {
            $snapshot[$field] = $reference[$field];
        }
        if (!is_int($snapshot['nodeId']) || $snapshot['nodeId'] < 1 ||
            !is_int($snapshot['size']) || $snapshot['size'] < 0 || !is_int($snapshot['mtime'])) {
            throw new \RuntimeException('Incomplete file identity');
        }
        foreach (['indexOwner', 'indexPath', 'owner', 'path', 'storageId', 'etag'] as $field) {
            if (!is_string($snapshot[$field]) || $snapshot[$field] === '') {
                throw new \RuntimeException('Incomplete file identity');
            }
        }
        $snapshot['revisionToken'] = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        return $snapshot;
    }
}
