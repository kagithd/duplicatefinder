<?php
namespace OCA\DuplicateFinder\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IShareOwnerlessMount;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;

/** Explicit, bounded observations; never authorizes actions or expands groups. */
class ReviewShareService {
    public function __construct(private ReviewService $review, private IRootFolder $root, private IManager $shares) {}

    public function page(int $appRef, int $depth = 0, int $type = IShare::TYPE_USER, int $offset = 0, int $limit = 25): array {
        if ($appRef < 1 || $depth < 0 || $depth > 32 || $offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 100 ||
            !in_array($type, [IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK], true)) {
            throw new \InvalidArgumentException('Invalid share page');
        }
        $observed = $this->review->reference($appRef);
        if (($observed['availability'] ?? null) !== 'available' || empty($observed['owner']) || empty($observed['nodeId'])) {
            throw new EvidenceConflictException('Reference unavailable');
        }
        $ownerRoot = $this->root->getUserFolder($observed['owner']);
        // Older supported releases may not expose this bounded lookup API.
        if (!method_exists($ownerRoot, 'getFirstNodeById')) throw new \RuntimeException('Owner lookup unavailable');
        $source = $ownerRoot->getFirstNodeById($observed['nodeId']);
        if (!$source instanceof File || $source->getId() !== $observed['nodeId'] ||
            $source->getOwner()?->getUID() !== $observed['owner'] || $source->getEtag() !== $observed['etag'] ||
            $source->getSize() !== $observed['size'] || $source->getMtime() !== $observed['mtime'] ||
            !str_starts_with($source->getPath(), rtrim($ownerRoot->getPath(), '/') . '/')) {
            throw new EvidenceConflictException('Owner reference changed');
        }
        $anchor = $source;
        $suffix = [];
        for ($i = 0; $i < $depth; $i++) {
            if ($anchor->getId() === $ownerRoot->getId()) throw new \InvalidArgumentException('Depth exceeds user root');
            array_unshift($suffix, $anchor->getName());
            $anchor = $anchor->getParent();
            if ($anchor->getPath() !== $ownerRoot->getPath() && !str_starts_with($anchor->getPath(), $ownerRoot->getPath() . '/')) {
                throw new EvidenceConflictException('Anchor escaped user root');
            }
        }
        if ($anchor->getMountPoint() instanceof IShareOwnerlessMount) throw new \RuntimeException('Unbounded ownerless provider is not supported');
        // onlyValid=false prevents Manager::checkShare() from deleting expired shares.
        $rows = $this->shares->getSharesBy($observed['owner'], $type, $anchor, true, $limit + 1, $offset, false);
        if (count($rows) > $limit + 1) throw new \RuntimeException('Provider ignored page limit');
        $more = count($rows) > $limit;
        $items = [];
        foreach (array_slice($rows, 0, $limit) as $share) {
            if ($share->getNodeId() !== $anchor->getId() || $share->getShareType() !== $type) throw new EvidenceConflictException('Share anchor changed');
            $item = ['id'=>$share->getId(), 'type'=>$type, 'recipient'=>$type === IShare::TYPE_LINK ? null : $share->getSharedWith(),
                'shareOwner'=>$share->getShareOwner(), 'sharedBy'=>$share->getSharedBy(), 'permissions'=>$share->getPermissions(),
                'expiration'=>$share->getExpirationDate()?->format(DATE_ATOM), 'status'=>$share->getStatus(),
                'recipientPath'=>null, 'effectivePermissions'=>null, 'deletable'=>null,
                'pathStatus'=>$type === IShare::TYPE_GROUP ? 'group_members_not_expanded' : 'not_resolved'];
            if ($type === IShare::TYPE_USER && is_string($item['recipient']) && $item['recipient'] !== '') {
                try {
                    $target = $share->getTarget();
                    if (!is_string($target) || !str_starts_with($target, '/') || in_array('..', explode('/', $target), true) || in_array('.', explode('/', $target), true)) throw new \RuntimeException('Invalid target');
                    $target = rtrim($target, '/') . ($suffix === [] ? '' : '/' . implode('/', $suffix));
                    $recipientNode = $this->root->getUserFolder($item['recipient'])->get($target);
                    if (!$recipientNode instanceof File || $recipientNode->getId() !== $source->getId() || $recipientNode->getOwner()?->getUID() !== $observed['owner']) {
                        throw new EvidenceConflictException('Recipient target differs');
                    }
                    $item['recipientPath'] = $recipientNode->getPath();
                    $item['effectivePermissions'] = $recipientNode->getPermissions();
                    $item['deletable'] = $recipientNode->isDeletable();
                    $item['pathStatus'] = 'observed';
                } catch (\Throwable $e) { $item['pathStatus'] = 'unverifiable'; }
            }
            // Deliberate allowlist: never include tokens, passwords, URLs or share notes.
            $items[] = $item;
        }
        if ($this->review->reference($appRef) !== $observed) throw new EvidenceConflictException('Reference changed during observation');
        $atRoot = $anchor->getId() === $ownerRoot->getId();
        return ['appRef'=>$appRef, 'observed'=>$observed, 'observedAt'=>time(), 'ownerPath'=>$source->getPath(),
            'anchor'=>['nodeId'=>$anchor->getId(),'path'=>$anchor->getPath(),'depth'=>$depth], 'type'=>$type,
            'items'=>$items, 'nextOffset'=>$more ? $offset + $limit : null,
            'nextDepth'=>!$atRoot && $depth < 32 ? $depth + 1 : null, 'complete'=>false,
            'limitations'=>['one_anchor_and_share_type_only','other_providers_not_qualified','group_members_not_expanded',
                'share_pages_not_atomic','native_revision_not_rechecked','no_execution_authorization']];
    }
}
