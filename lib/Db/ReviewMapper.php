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
            ->selectAlias($qb->createFunction('MIN(path)'), 'sample_path')
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
                'samplePath' => $row['sample_path'],
            ], $result->fetchAll());
        } finally {
            $result->closeCursor();
        }
    }

    /** Indexed duplicate candidates with no report at all; not a freshness classification. */
    public function missingFindings(int $cursor, int $limit, string $owner = '', string $folder = '', string $mime = ''): array
    {
        if ($cursor < 0 || $limit < 1 || $limit > 101) throw new \InvalidArgumentException('Invalid page');
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.id', 'f.owner', 'f.path', 'f.file_hash', 'f.mimetype', 'f.ignored')
            ->from('duplicatefinder_finfo', 'f')
            ->where($qb->expr()->gt('f.id', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('f.ignored', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        if ($owner !== '') $qb->andWhere($qb->expr()->eq('f.owner', $qb->createNamedParameter($owner)));
        if ($mime !== '') $qb->andWhere($qb->expr()->eq('f.mimetype', $qb->createNamedParameter($mime)));
        if ($folder !== '') {
            $prefix = rtrim($folder, '/') . '/';
            $qb->andWhere($qb->expr()->eq($qb->createFunction('SUBSTR(f.path, 1, ' . $qb->createNamedParameter(mb_strlen($prefix, 'UTF-8'), IQueryBuilder::PARAM_INT) . ')'), $qb->createNamedParameter($prefix)));
        }
        $evidence = $this->db->getQueryBuilder();
        $evidence->select('e.id')->from('df_review_evidence', 'e')->where($evidence->expr()->eq('e.app_ref', 'f.id'));
        $remaining = 'f.file_hash';
        foreach (str_split('0123456789abcdef') as $digit) $remaining = "REPLACE($remaining, '$digit', '')";
        $peer = $this->db->getQueryBuilder();
        $peer->select('p.id')->from('duplicatefinder_finfo', 'p')
            ->where($peer->expr()->eq('p.file_hash', 'f.file_hash'))
            ->andWhere($peer->expr()->neq('p.id', 'f.id'))
            ->andWhere($peer->expr()->eq('p.ignored', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        // Guard expensive validation: already reported references cannot match.
        // A plain AND allows SQLite to validate every hash before its subquery.
        $qb->andWhere($qb->createFunction('CASE WHEN EXISTS (' . $evidence->getSQL() . ') THEN 0 '
            . 'WHEN LENGTH(f.file_hash) = 64 AND ' . $remaining . " = '' AND EXISTS (" . $peer->getSQL()
            . ') THEN 1 ELSE 0 END = 1'));
        $qb->orderBy('f.id', 'ASC')->setMaxResults($limit);
        $result = $qb->executeQuery();
        try { return $result->fetchAll(); }
        finally { $result->closeCursor(); }
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
