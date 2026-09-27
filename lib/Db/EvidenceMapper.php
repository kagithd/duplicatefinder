<?php
namespace OCA\DuplicateFinder\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Append-only application interface. History survives removal of index rows. */
class EvidenceMapper
{
    private IDBConnection $db;
    public function __construct(IDBConnection $db) { $this->db = $db; }

    public function append(int $appRef, int $createdAt, string $json): int
    {
        $qb = $this->db->getQueryBuilder();
        $metadata = \OCA\DuplicateFinder\Service\EvidenceSearchMetadata::fromJson($json);
        $qb->insert('df_review_evidence')->values([
            'app_ref' => $qb->createNamedParameter($appRef, IQueryBuilder::PARAM_INT),
            'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
            'record_json' => $qb->createNamedParameter($json),
            'search_status' => $qb->createNamedParameter($metadata['status']),
            'search_format' => $qb->createNamedParameter($metadata['format'], $metadata['format'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
        ])->executeStatement();
        return (int)$qb->getLastInsertId();
    }

    /** Bounded, restartable projection; original reports are never updated. */
    public function backfillSearchMetadata(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 100) throw new \InvalidArgumentException('Invalid metadata batch size');
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'record_json')->from('df_review_evidence')
            ->where($qb->expr()->isNull('search_status'))->orderBy('id', 'ASC')->setMaxResults($limit);
        $result = $qb->executeQuery();
        try { $rows = $result->fetchAll(); }
        finally { $result->closeCursor(); }
        foreach ($rows as $row) {
            $metadata = \OCA\DuplicateFinder\Service\EvidenceSearchMetadata::fromJson($row['record_json']);
            $update = $this->db->getQueryBuilder();
            $update->update('df_review_evidence')
                ->set('search_status', $update->createNamedParameter($metadata['status']))
                ->set('search_format', $update->createNamedParameter($metadata['format'], $metadata['format'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
                ->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
                ->andWhere($update->expr()->isNull('search_status'))->executeStatement();
        }
        return count($rows);
    }
    /** Latest historical report per reference, not a current-validity claim. */
    public function latestSearch(int $cursor, int $limit, string $status = '', string $format = ''): array
    {
        if ($cursor < 0 || $limit < 1 || $limit > 101 || strlen($format) > 128 ||
            !in_array($status, ['', 'passed', 'corrupt', 'unsupported', 'inaccessible', 'limit', 'stale', 'error', 'invalid'], true)) {
            throw new \InvalidArgumentException('Invalid evidence search');
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('e.id', 'e.app_ref', 'e.created_at', 'e.record_json', 'e.search_status', 'e.search_format')
            ->from('df_review_evidence', 'e');
        $newer = $this->db->getQueryBuilder();
        $newer->select('newer.id')->from('df_review_evidence', 'newer')
            ->where($newer->expr()->eq('newer.app_ref', 'e.app_ref'))
            ->andWhere($newer->expr()->gt('newer.id', 'e.id'));
        // An existence check stops at the first successor instead of joining
        // every newer report for every historical match.
        $qb->where($qb->createFunction('NOT EXISTS (' . $newer->getSQL() . ')'));
        // Newer rows are intentionally independent of cursor and filters.
        if ($cursor > 0) $qb->andWhere($qb->expr()->lt('e.id', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)));
        if ($status !== '') $qb->andWhere($qb->expr()->eq('e.search_status', $qb->createNamedParameter($status)));
        if ($format !== '') $qb->andWhere($qb->expr()->eq('e.search_format', $qb->createNamedParameter($format)));
        $qb->orderBy('e.id', 'DESC')->setMaxResults($limit);
        $result = $qb->executeQuery();
        try { return $result->fetchAll(); }
        finally { $result->closeCursor(); }
    }
    public function hasUnprojectedEvidence(): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('df_review_evidence')->where($qb->expr()->isNull('search_status'))->setMaxResults(1);
        $result = $qb->executeQuery();
        try { return $result->fetch() !== false; }
        finally { $result->closeCursor(); }
    }
    public function history(int $appRef, int $cursor, int $limit): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'created_at', 'record_json')->from('df_review_evidence')
            ->where($qb->expr()->eq('app_ref', $qb->createNamedParameter($appRef, IQueryBuilder::PARAM_INT)));
        if ($cursor > 0) $qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)));
        $qb->orderBy('id', 'DESC')->setMaxResults($limit);
        $result = $qb->executeQuery();
        try { return $result->fetchAll(); }
        finally { $result->closeCursor(); }
    }
}
