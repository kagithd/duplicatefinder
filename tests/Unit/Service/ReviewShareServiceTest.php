<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;
use OCA\DuplicateFinder\Service\ReviewShareService;
use OCA\DuplicateFinder\Service\ReviewService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
class ReviewShareServiceTest extends TestCase {
    private function fixture(): array {
        $observed=['id'=>1,'owner'=>'alice','nodeId'=>42,'etag'=>'v1','size'=>12,'mtime'=>100,'availability'=>'available'];
        $review=$this->createMock(ReviewService::class); $review->method('reference')->willReturn($observed);
        $owner=$this->createMock(IUser::class); $owner->method('getUID')->willReturn('alice');
        $file=$this->createMock(File::class); $file->method('getId')->willReturn(42);
        $file->method('getOwner')->willReturn($owner); $file->method('getPath')->willReturn('/alice/files/a.png');
        $file->method('getEtag')->willReturn('v1'); $file->method('getSize')->willReturn(12); $file->method('getMtime')->willReturn(100);
        $file->expects($this->never())->method('getContent'); $file->expects($this->never())->method('fopen');
        $folder=$this->createMock(Folder::class); $folder->method('getId')->willReturn(2);
        $folder->method('getPath')->willReturn('/alice/files'); $folder->method('getFirstNodeById')->with(42)->willReturn($file);
        $file->method('getParent')->willReturn($folder);
        $root=$this->createMock(IRootFolder::class); $root->method('getUserFolder')->willReturn($folder);
        $manager=$this->createMock(IManager::class);
        return [new ReviewShareService($review,$root,$manager),$manager,$file];
    }
    public function testBoundedGroupPageDoesNotExpandUsersOrExposeSecrets(): void {
        [$service,$manager,$file]=$this->fixture();
        $share=$this->createMock(IShare::class); $share->method('getId')->willReturn('7');
        $share->method('getNodeId')->willReturn(42); $share->method('getShareType')->willReturn(IShare::TYPE_GROUP);
        $share->method('getSharedWith')->willReturn('editors'); $share->method('getPermissions')->willReturn(1);
        $share->expects($this->never())->method('getToken'); $share->expects($this->never())->method('getPassword');
        $manager->expects($this->once())->method('getSharesBy')->with('alice',IShare::TYPE_GROUP,$file,true,2,0,false)->willReturn([$share,$share]);
        $manager->expects($this->never())->method('getAccessList');
        $page=$service->page(1,0,IShare::TYPE_GROUP,0,1);
        $this->assertCount(1,$page['items']); $this->assertSame(1,$page['nextOffset']);
        $this->assertSame('editors',$page['items'][0]['recipient']);
        $this->assertSame('group_members_not_expanded',$page['items'][0]['pathStatus']);
        $this->assertNull($page['items'][0]['recipientPath']);
        $this->assertSame('/alice/files/a.png',$page['ownerPath']);
        $this->assertFalse($page['complete']);
    }
    public function testInvalidPageDoesNotQueryProvider(): void {
        [$service,$manager]=$this->fixture(); $manager->expects($this->never())->method('getSharesBy');
        $this->expectException(\InvalidArgumentException::class); $service->page(1,0,1,0,101);
    }
    public function testRootEscapeIsRejected(): void {
        [$service,$manager]=$this->fixture(); $manager->expects($this->never())->method('getSharesBy');
        $this->expectException(\InvalidArgumentException::class); $service->page(1,2,1,0,10);
    }
}
