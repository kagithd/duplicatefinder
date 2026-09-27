<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;

use OCA\DuplicateFinder\Service\EvidenceSnapshotService;
use OCA\DuplicateFinder\Service\ReviewService;
use PHPUnit\Framework\TestCase;

class EvidenceSnapshotServiceTest extends TestCase
{
    private function reference(): array
    {
        return ['id' => 7, 'indexOwner' => 'alice', 'indexPath' => '/alice/files/a', 'owner' => 'alice',
            'path' => '/alice/files/a', 'nodeId' => 42, 'storageId' => 'home::alice', 'etag' => 'rev1',
            'size' => 123, 'mtime' => 1000, 'availability' => 'available', 'integrity' => 'not_checked'];
    }

    public function testRevisionTokenChangesForEveryObservedIdentityOrRevisionField(): void
    {
        $reference = $this->reference();
        $review = $this->createMock(ReviewService::class);
        $review->method('reference')->willReturn($reference);
        $baseline = (new EvidenceSnapshotService($review))->snapshot(7);
        $this->assertSame(7, $baseline['appRef']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $baseline['revisionToken']);
        foreach (['indexOwner' => 'bob', 'indexPath' => '/bob/files/a', 'owner' => 'bob', 'path' => '/other', 'nodeId' => 43, 'storageId' => 'other', 'etag' => 'rev2', 'size' => 124, 'mtime' => 1001] as $field => $value) {
            $review = $this->createMock(ReviewService::class);
            $review->method('reference')->willReturn(array_merge($reference, [$field => $value]));
            $changed = (new EvidenceSnapshotService($review))->snapshot(7);
            $this->assertNotSame($baseline['revisionToken'], $changed['revisionToken'], $field);
        }
    }

    public function testMissingAndUnavailableReferencesCannotProduceRevisionTokens(): void
    {
        foreach ([null, array_merge($this->reference(), ['availability' => 'unavailable'])] as $reference) {
            $review = $this->createMock(ReviewService::class);
            $review->method('reference')->willReturn($reference);
            try {
                (new EvidenceSnapshotService($review))->snapshot(7);
                $this->fail('Unavailable reference accepted');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
