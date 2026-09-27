<?php
namespace OCA\DuplicateFinder\Tests\Unit\Controller;
use OCA\DuplicateFinder\Controller\PlanController;
use OCA\DuplicateFinder\Service\PlanService;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
class PlanControllerTest extends TestCase  {
    public function testAnonymousCannotUseAnyMethod():void  {
        $s=$this->createMock(PlanService::class);
        $s->expects($this->never())->method('create');
        $c=new PlanController('duplicatefinder',$this->createMock(IRequest::class),$this->createMock(IUserSession::class),$this->createMock(IGroupManager::class),$s);
        foreach([$c->create([]),$c->append('x',[]),$c->listing(),$c->revision('x',1),$c->export('x',1)] as $r)$this->assertSame(403,$r->getStatus());
    }
    public function testNoAuthenticationOrCsrfExemptions():void  {
        $r=new \ReflectionClass(PlanController::class);
        foreach($r->getMethods(\ReflectionMethod::IS_PUBLIC) as $m)if($m->getDeclaringClass()->getName()===PlanController::class) {
            $doc=$m->getDocComment()?:'';
            $this->assertDoesNotMatchRegularExpression('/@(NoCSRFRequired|NoAdminRequired|PublicPage)/',$doc);
        }
    }
}
