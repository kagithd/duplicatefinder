<?php
namespace OCA\DuplicateFinder\Tests\Unit\Controller;
use OCA\DuplicateFinder\Controller\ReviewGroupController;
use OCA\DuplicateFinder\Service\ReviewGroupService;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use OCP\{IRequest,IUserSession,IGroupManager,IUser};
use PHPUnit\Framework\TestCase;
class ReviewGroupControllerTest extends TestCase {
    private function controller($service, bool $admin): ReviewGroupController {
        $user=$this->createMock(IUser::class); $user->method('getUID')->willReturn('reviewer');
        $session=$this->createMock(IUserSession::class); $session->method('getUser')->willReturn($user);
        $groups=$this->createMock(IGroupManager::class); $groups->method('isAdmin')->with('reviewer')->willReturn($admin);
        return new ReviewGroupController('duplicatefinder',$this->createMock(IRequest::class),$session,$groups,$service);
    }
    public function testNonAdminNeverReadsMembers(): void {
        $s=$this->createMock(ReviewGroupService::class); $s->expects($this->never())->method('page');
        $this->assertSame(403,$this->controller($s,false)->page(1,0,0,'42')->getStatus());
    }
    public function testExactPageArgumentsAndDataReturned(): void {
        $s=$this->createMock(ReviewGroupService::class); $s->expects($this->once())->method('page')->with(7,2,10,'42',50,5)->willReturn(['items'=>[],'complete'=>false]);
        $r=$this->controller($s,true)->page(7,2,10,'42',50,5);
        $this->assertSame(200,$r->getStatus()); $this->assertFalse($r->getData()['complete']);
    }
    /** @dataProvider failures */
    public function testFailuresNeverExposeInternalMessages(\Throwable $failure,int $status): void {
        $s=$this->createMock(ReviewGroupService::class); $s->method('page')->willThrowException($failure);
        $r=$this->controller($s,true)->page(1,0,0,'42');
        $this->assertSame($status,$r->getStatus()); $this->assertStringNotContainsString('private-token',json_encode($r->getData()));
    }
    public static function failures(): array {
        return [[new \InvalidArgumentException('private-token'),400],[new EvidenceConflictException('private-token'),409],[new \RuntimeException('private-token'),503]];
    }
}
