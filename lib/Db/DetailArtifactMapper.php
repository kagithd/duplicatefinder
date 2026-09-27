<?php
namespace OCA\DuplicateFinder\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Append-only artifact storage; no updates, deletion or candidate-index cascade. */
class DetailArtifactMapper
{
    private IDBConnection $db;
    public function __construct(IDBConnection $db) { $this->db = $db; }

    public function find(int $appRef, int $evidenceId, string $selection): ?array
    {
        return $this->lookup($appRef, $evidenceId, 'selection_hash', $selection, IQueryBuilder::PARAM_STR);
    }

    public function findById(int $appRef, int $evidenceId, int $detailId): ?array
    {
        return $this->lookup($appRef, $evidenceId, 'id', $detailId, IQueryBuilder::PARAM_INT);
    }

    private function lookup(int $appRef, int $evidenceId, string $column, $value, int $type): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'created_at', 'record_json')->from('df_detail_artifacts')
            ->where($qb->expr()->eq('app_ref', $qb->createNamedParameter($appRef, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('evidence_id', $qb->createNamedParameter($evidenceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, $type)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        try {
            $row = $result->fetch();
            return $row === false ? null : $row;
        } finally {
            $result->closeCursor();
        }
    }

    public function append(int $appRef, int $evidenceId, string $selection, int $createdAt, string $json): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('df_detail_artifacts')->values([
            'app_ref' => $qb->createNamedParameter($appRef, IQueryBuilder::PARAM_INT),
            'evidence_id' => $qb->createNamedParameter($evidenceId, IQueryBuilder::PARAM_INT),
            'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
            'record_json' => $qb->createNamedParameter($json),
            'selection_hash' => $qb->createNamedParameter($selection),
        ])->executeStatement();
        return (int)$qb->getLastInsertId();
    }
}
