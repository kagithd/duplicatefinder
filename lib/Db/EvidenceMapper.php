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
        $qb->insert('df_review_evidence')->values([
            'app_ref' => $qb->createNamedParameter($appRef, IQueryBuilder::PARAM_INT),
            'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
            'record_json' => $qb->createNamedParameter($json),
        ])->executeStatement();
        return (int)$qb->getLastInsertId();
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
