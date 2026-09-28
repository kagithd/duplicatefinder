<?php
namespace OCA\DuplicateFinder\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Append-only content observations, separate from decoder findings and candidate hashes. */
class ContentEvidenceMapper {
    private IDBConnection $db;
    public function __construct(IDBConnection $db) { $this->db = $db; }
    public function append(int $appRef, int $createdAt, string $json): int {
        if ($appRef < 1 || $createdAt < 1 || strlen($json) > 32768) throw new \InvalidArgumentException('Invalid content record');
        $qb = $this->db->getQueryBuilder();
        $qb->insert('df_content_evidence')->values([
            'app_ref'=>$qb->createNamedParameter($appRef,IQueryBuilder::PARAM_INT),
            'created_at'=>$qb->createNamedParameter($createdAt,IQueryBuilder::PARAM_INT),
            'record_json'=>$qb->createNamedParameter($json),
        ])->executeStatement();
        return (int)$qb->getLastInsertId();
    }
    public function history(int $appRef, int $cursor, int $limit): array {
        if ($appRef < 1 || $cursor < 0 || $limit < 1 || $limit > 101) throw new \InvalidArgumentException('Invalid content page');
        $qb = $this->db->getQueryBuilder();
        $qb->select('id','created_at','record_json')->from('df_content_evidence')
            ->where($qb->expr()->eq('app_ref',$qb->createNamedParameter($appRef,IQueryBuilder::PARAM_INT)));
        if ($cursor > 0) $qb->andWhere($qb->expr()->lt('id',$qb->createNamedParameter($cursor,IQueryBuilder::PARAM_INT)));
        $result = $qb->orderBy('id','DESC')->setMaxResults($limit)->executeQuery();
        try { return $result->fetchAll(); } finally { $result->closeCursor(); }
    }
}
