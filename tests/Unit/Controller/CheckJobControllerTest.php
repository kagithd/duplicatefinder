<?php
namespace OCA\DuplicateFinder\Tests\Unit\Controller;
use OCA\DuplicateFinder\Controller\CheckJobController;
use OCA\DuplicateFinder\Service\CheckJobService;
use OCP\{IRequest,IUserSession,IGroupManager};
use PHPUnit\Framework\TestCase;
class CheckJobControllerTest extends TestCase {
    public function testAnonymousCannotUseQueueEndpoints(): void {
        $s=$this->createMock(CheckJobService::class);
        $s->expects($this->never())->method('create');
        $c=new CheckJobController('duplicatefinder',$this->createMock(IRequest::class),$this->createMock(IUserSession::class),$this->createMock(IGroupManager::class),$s);
        foreach([$c->create([]),$c->listing(),$c->get('x'),$c->cancel('x')] as $r) $this->assertSame(403,$r->getStatus());
    }
}
