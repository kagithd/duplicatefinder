<?php

namespace OCA\DuplicateFinder\Service;

use OCA\DuplicateFinder\Db\ReviewMapper;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserManager;

class ReviewService
{
    private ReviewMapper $mapper;
    private IRootFolder $root;
    private IUserManager $users;

    public function __construct(ReviewMapper $mapper, IRootFolder $root, IUserManager $users)
    {
        $this->mapper = $mapper;
        $this->root = $root;
        $this->users = $users;
    }

    public function reference(int $appRef): ?array
    {
        $row = $this->mapper->reference($appRef);
        return $row === null ? null : $this->observe($row);
    }
    public function groups(string $cursor, int $limit): array
    {
        $rows = $this->mapper->groups($cursor, $limit + 1);
        $more = count($rows) > $limit;
        $items = array_slice($rows, 0, $limit);
        return ['items' => $items, 'nextCursor' => $more ? end($items)['hash'] : null];
    }

    public function members(string $hash, int $cursor, int $limit): array
    {
        $rows = $this->mapper->members($hash, $cursor, $limit + 1);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'items' => array_map(fn (array $row): array => $this->observe($row), $rows),
            'nextCursor' => $more ? (int)end($rows)['id'] : null,
        ];
    }

    private function observe(array $row): array
    {
        $item = [
            'id' => (int)$row['id'], 'candidateHash' => $row['file_hash'] ?? null, 'indexOwner' => $row['owner'], 'indexPath' => $row['path'],
            'owner' => null, 'path' => null, 'nodeId' => null, 'storageId' => null,
            'etag' => null, 'size' => null, 'mtime' => null,
            'availability' => 'unavailable', 'integrity' => 'not_checked',
            'hashStatus' => 'indexed_unverified',
        ];
        try {
            if (!$row['owner'] || !$this->users->userExists($row['owner'])) {
                return $item;
            }
            $folder = $this->root->getUserFolder($row['owner']);
            $prefix = rtrim($folder->getPath(), '/') . '/';
            if (!str_starts_with($row['path'], $prefix)) {
                return $item;
            }
            $relative = substr($row['path'], strlen($prefix));
            if (in_array('..', explode('/', $relative), true) || in_array('.', explode('/', $relative), true)) {
                return $item;
            }
            $node = $folder->get($relative);
            if (!$node instanceof File || $node->getPath() !== $row['path']) {
                return $item;
            }
            // Build the metadata response together (not a locked snapshot). A failed lookup leaves the
            // original reference visible without suggesting a current identity.
            $metadata = [
                'owner' => $node->getOwner() ? $node->getOwner()->getUID() : null,
                'path' => $node->getPath(), 'nodeId' => $node->getId(),
                'storageId' => $node->getStorage()->getId(), 'etag' => $node->getEtag(),
                'size' => $node->getSize(), 'mtime' => $node->getMtime(),
                'availability' => 'available',
            ];
            return array_merge($item, $metadata);
        } catch (\Throwable $e) {
            // Review never removes stale index references or reads file content.
            return $item;
        }
    }
}
