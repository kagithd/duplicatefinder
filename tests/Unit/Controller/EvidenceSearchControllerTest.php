<?php
namespace OCA\DuplicateFinder\Tests\Unit\Controller;
use OCA\DuplicateFinder\Controller\EvidenceSearchController;
use OCA\DuplicateFinder\Service\EvidenceSearchService;
use OCP\IRequest;use OCP\IUser;use OCP\IUserSession;use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
class EvidenceSearchControllerTest extends TestCase {
    private function controller(?string $uid,bool $admin,$service):EvidenceSearchController {
        $session=$this->createMock(IUserSession::class);
        if($uid!==null){$user=$this->createMock(IUser::class);$user->method('getUID')->willReturn($uid);$session->method('getUser')->willReturn($user);}
        $groups=$this->createMock(IGroupManager::class);$groups->method('isAdmin')->willReturn($admin);
        return new EvidenceSearchController('duplicatefinder',$this->createMock(IRequest::class),$session,$groups,$service);
    }
    public function testAnonymousAndNormalUsersNeverSearch():void {
        foreach([null,'reader'] as $uid){$s=$this->createMock(EvidenceSearchService::class);$s->expects($this->never())->method('search');$this->assertSame(403,$this->controller($uid,false,$s)->search()->getStatus());}
    }
    public function testAdminPassesExactFiltersAndInvalidInputIsBadRequest():void {
        $s=$this->createMock(EvidenceSearchService::class);$s->expects($this->once())->method('search')->with(17,10,'corrupt','PNG')->willReturn(['items'=>[],'metadataComplete'=>false]);
        $response=$this->controller('admin',true,$s)->search(17,10,'corrupt','PNG');$this->assertSame(200,$response->getStatus());$this->assertFalse($response->getData()['metadataComplete']);
        $s=$this->createMock(EvidenceSearchService::class);$s->method('search')->willThrowException(new \InvalidArgumentException());
        $this->assertSame(400,$this->controller('admin',true,$s)->search(-1)->getStatus());
    }
}