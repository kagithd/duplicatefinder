<?php
namespace OCA\DuplicateFinder\Tests\Unit\Controller;
use OCA\DuplicateFinder\Controller\WorkerControlController;
use OCA\DuplicateFinder\Service\WorkerControlService;
use OCP\{IRequest,IUser,IUserSession,IGroupManager};
use PHPUnit\Framework\TestCase;

class WorkerControlControllerTest extends TestCase {
    private function controller(?string $uid,bool $admin,WorkerControlService $service): WorkerControlController {
        $session=$this->createMock(IUserSession::class);
        if($uid!==null) { $user=$this->createMock(IUser::class);$user->method('getUID')->willReturn($uid);$session->method('getUser')->willReturn($user); }
        $groups=$this->createMock(IGroupManager::class);$groups->method('isAdmin')->willReturn($admin);
        return new WorkerControlController('duplicatefinder',$this->createMock(IRequest::class),$session,$groups,$service);
    }
    public function testAnonymousAndOrdinaryUsersCannotControlWorkers(): void {
        foreach([null,'reader'] as $uid) {
            $service=$this->createMock(WorkerControlService::class);$service->expects($this->never())->method('control');
            $c=$this->controller($uid,false,$service);
            foreach([$c->status(),$c->start(),$c->stop(str_repeat('a',32))] as $r) $this->assertSame(403,$r->getStatus());
        }
    }
    public function testUnavailableControllerIsNotAnHttpSuccess(): void {
        $service=$this->createMock(WorkerControlService::class);
        $service->method('control')->willReturn(['available'=>false,'reason'=>'unreachable']);
        $r=$this->controller('admin',true,$service)->status();
        $this->assertSame(503,$r->getStatus());$this->assertFalse($r->getData()['available']);
    }
    public function testStaleStopIsConflictAndKeepsTheRunIdentity(): void {
        $service=$this->createMock(WorkerControlService::class);
        $service->expects($this->once())->method('control')->with('stop',str_repeat('a',32))->willReturn(['available'=>true,'error'=>'generation_conflict']);
        $this->assertSame(409,$this->controller('admin',true,$service)->stop(str_repeat('a',32))->getStatus());
    }
    public function testInvalidRequestIsBadRequest(): void {
        $service=$this->createMock(WorkerControlService::class);
        $service->method('control')->willThrowException(new \InvalidArgumentException());
        $this->assertSame(400,$this->controller('admin',true,$service)->stop('bad')->getStatus());
    }
    public function testAdminStartReturnsObservedState(): void {
        $state=['available'=>true,'state'=>'running','runId'=>str_repeat('b',32),'exitCode'=>null];
        $service=$this->createMock(WorkerControlService::class);
        $service->expects($this->once())->method('control')->with('start',null)->willReturn($state);
        $r=$this->controller('admin',true,$service)->start();
        $this->assertSame(200,$r->getStatus());$this->assertSame($state,$r->getData());
    }
}
