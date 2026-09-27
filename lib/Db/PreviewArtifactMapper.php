<?php
namespace OCA\DuplicateFinder\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Append-only artifact storage; no updates, deletion or candidate-index cascade. */
class PreviewArtifactMapper
{
    private IDBConnection $db;
    public function __construct(IDBConnection $db) { $this->db = $db; }

    public function find(int $appRef, int $evidenceId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'created_at', 'record_json')->from('df_preview_artifacts')
            ->where($qb->expr()->eq('app_ref', $qb->createNamedParameter($appRef, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('evidence_id', $qb->createNamedParameter($evidenceId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        try {
            $row = $result->fetch();
            return $row === false ? null : $row;
        } finally {
            $result->closeCursor();
        }
    }

    public function append(int $appRef, int $evidenceId, int $createdAt, string $json): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('df_preview_artifacts')->values([
            'app_ref' => $qb->createNamedParameter($appRef, IQueryBuilder::PARAM_INT),
            'evidence_id' => $qb->createNamedParameter($evidenceId, IQueryBuilder::PARAM_INT),
            'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
            'record_json' => $qb->createNamedParameter($json),
        ])->executeStatement();
        return (int)$qb->getLastInsertId();
    }
}
