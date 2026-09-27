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
        $qb->select('id', 'owner', 'path', 'file_hash', 'ignored')->from('duplicatefinder_finfo')
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
    public function candidateHash(int $appRef): ?string
    {
        $row = $this->reference($appRef);
        return $row !== null && !(bool)$row['ignored'] ? $row['file_hash'] : null;
    }
    public function groups(string $cursor, int $limit, string $owner = '', string $folder = ''): array
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
        // Select matching hashes before global aggregation. Filtering the outer
        // rows would hide copies belonging to other users and change counts.
        if ($owner !== '' || $folder !== '') {
            $scope = $this->db->getQueryBuilder();
            $scope->select('scope.file_hash')->from('duplicatefinder_finfo', 'scope')
                ->where($scope->expr()->eq('scope.ignored', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
                ->andWhere($scope->expr()->gt('scope.file_hash', $qb->createNamedParameter($cursor)));
            if ($owner !== '') $scope->andWhere($scope->expr()->eq('scope.owner', $qb->createNamedParameter($owner)));
            if ($folder !== '') {
                $prefix = rtrim($folder, '/') . '/';
                $scope->andWhere($scope->expr()->eq(
                    $scope->createFunction('SUBSTR(scope.path, 1, ' . $qb->createNamedParameter(mb_strlen($prefix, 'UTF-8'), IQueryBuilder::PARAM_INT) . ')'),
                    $qb->createNamedParameter($prefix)));
            }
            // Bind all parameters on the outer builder; the subquery stays SQL-only.
            $qb->andWhere($qb->expr()->in('file_hash', $qb->createFunction($scope->getSQL())));
        }
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
        $qb->select('id', 'owner', 'path', 'file_hash', 'ignored')
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
