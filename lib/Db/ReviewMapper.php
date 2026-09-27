<?php

namespace OCA\DuplicateFinder\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Read-only queries against the existing hash index; no separate index. */
class ReviewMapper
{
    private IDBConnection $db;

    public function __construct(IDBConnection $db)
    {
        $this->db = $db;
    }

    public function reference(int $appRef): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'owner', 'path')->from('duplicatefinder_finfo')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($appRef, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        try {
            $row = $result->fetch();
            return $row === false ? null : $row;
        } finally {
            $result->closeCursor();
        }
    }
    public function groups(string $cursor, int $limit): array
    {
        $qb = $this->db->getQueryBuilder();
        // Portable SHA-256 validation (SQLite, PostgreSQL and MySQL). The index
        // contains canonical lowercase SHA-256 values, not image hashes.
        $remaining = 'file_hash';
        foreach (str_split('0123456789abcdef') as $digit) {
            $remaining = "REPLACE($remaining, '$digit', '')";
        }
        $qb->select('file_hash')
            ->selectAlias($qb->createFunction('COUNT(*)'), 'reference_count')
            ->from('duplicatefinder_finfo')
            ->where($qb->expr()->eq('ignored', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->gt('file_hash', $qb->createNamedParameter($cursor)))
            ->andWhere($qb->expr()->eq($qb->createFunction('LENGTH(file_hash)'), $qb->createNamedParameter(64, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq($qb->createFunction($remaining), $qb->createNamedParameter('')))
            ->groupBy('file_hash')
            ->having($qb->expr()->gte($qb->createFunction('COUNT(*)'), $qb->createNamedParameter(2, IQueryBuilder::PARAM_INT)))
            ->orderBy('file_hash', 'ASC')
            ->setMaxResults($limit);
        $result = $qb->executeQuery();
        try {
            return array_map(static fn (array $row): array => [
                'hash' => $row['file_hash'],
                'referenceCount' => (int)$row['reference_count'],
            ], $result->fetchAll());
        } finally {
            $result->closeCursor();
        }
    }

    public function members(string $hash, int $cursor, int $limit): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'owner', 'path')
            ->from('duplicatefinder_finfo')
            ->where($qb->expr()->eq('file_hash', $qb->createNamedParameter($hash)))
            ->andWhere($qb->expr()->eq('ignored', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit);
        $result = $qb->executeQuery();
        try {
            return $result->fetchAll();
        } finally {
            $result->closeCursor();
        }
    }
}
