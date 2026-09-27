<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;

use OCA\DuplicateFinder\Db\ReviewMapper;
use OCA\DuplicateFinder\Service\ReviewService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class ReviewServiceTest extends TestCase
{
    public function testGroupsUseLookaheadAndNeverResolveFiles(): void
    {
        $mapper = $this->createMock(ReviewMapper::class);
        $mapper->method('groups')->with('', 2)->willReturn([
            ['hash' => str_repeat('a', 64), 'referenceCount' => 3],
            ['hash' => str_repeat('b', 64), 'referenceCount' => 2],
        ]);
        $root = $this->createMock(IRootFolder::class);
        $root->expects($this->never())->method('getUserFolder');
        $service = new ReviewService($mapper, $root, $this->createMock(IUserManager::class));
        $page = $service->groups('', 1);
        $this->assertCount(1, $page['items']);
        $this->assertSame(3, $page['items'][0]['referenceCount']);
        $this->assertSame(str_repeat('a', 64), $page['nextCursor']);
    }

    public function testUnavailableReferencesRemainVisibleAndPaginationDoesNotResolveLookahead(): void
    {
        $mapper = $this->createMock(ReviewMapper::class);
        $mapper->method('members')->willReturn([
            ['id' => 7, 'owner' => 'gone', 'path' => '/gone/files/folder/a'],
            ['id' => 8, 'owner' => 'other', 'path' => '/other/files/b'],
        ]);
        $users = $this->createMock(IUserManager::class);
        $users->expects($this->once())->method('userExists')->with('gone')->willReturn(false);
        $root = $this->createMock(IRootFolder::class);
        $root->expects($this->never())->method('getUserFolder');
        $page = (new ReviewService($mapper, $root, $users))->members(str_repeat('a', 64), 0, 1);
        $this->assertSame(7, $page['nextCursor']);
        $this->assertSame('/gone/files/folder/a', $page['items'][0]['indexPath']);
        $this->assertSame('gone', $page['items'][0]['indexOwner']);
        $this->assertSame('unavailable', $page['items'][0]['availability']);
        $this->assertSame('not_checked', $page['items'][0]['integrity']);
        $this->assertNull($page['items'][0]['etag']);
    }

    public function testAccessibleReferenceReportsObservedMetadataWithoutReadingContents(): void
    {
        $mapper = $this->createMock(ReviewMapper::class);
        $mapper->method('members')->willReturn([['id' => 7, 'owner' => 'alice', 'path' => '/alice/files/folder/a']]);
        $users = $this->createMock(IUserManager::class);
        $users->method('userExists')->willReturn(true);
        $file = $this->createMock(File::class);
        $file->method('getPath')->willReturn('/alice/files/folder/a');
        $file->method('getId')->willReturn(42);
        $owner = $this->createMock(IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $file->method('getOwner')->willReturn($owner);
        $file->method('getEtag')->willReturn('revision');
        $file->method('getSize')->willReturn(1024);
        $file->method('getMtime')->willReturn(1000);
        $file->expects($this->never())->method('getContent');
        $file->expects($this->never())->method('fopen');
        $storage = $this->createMock(IStorage::class);
        $storage->method('getId')->willReturn('home::alice');
        $file->method('getStorage')->willReturn($storage);
        $folder = $this->createMock(Folder::class);
        $folder->method('getPath')->willReturn('/alice/files');
        $folder->method('get')->with('folder/a')->willReturn($file);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->with('alice')->willReturn($folder);
        $page = (new ReviewService($mapper, $root, $users))->members(str_repeat('a', 64), 0, 50);
        $this->assertNull($page['nextCursor']);
        $this->assertSame(42, $page['items'][0]['nodeId']);
        $this->assertSame('home::alice', $page['items'][0]['storageId']);
        $this->assertSame('revision', $page['items'][0]['etag']);
        $this->assertSame('not_checked', $page['items'][0]['integrity']);
    }
}
