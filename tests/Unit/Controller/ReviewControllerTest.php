<?php
namespace OCA\DuplicateFinder\Tests\Unit\Controller;

use OCA\DuplicateFinder\Controller\ReviewController;
use OCA\DuplicateFinder\Service\ReviewService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

class ReviewControllerTest extends TestCase
{
    private function controller(?string $uid, bool $admin, $service): ReviewController
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $session->method('getUser')->willReturn($user);
        }
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($admin);
        return new ReviewController('duplicatefinder', $this->createMock(IRequest::class), $session, $groups, $service);
    }

    public function testUnauthenticatedAndOrdinaryUsersCannotReadAnyReviewEndpoint(): void
    {
        foreach ([null, 'reader'] as $uid) {
            $service = $this->createMock(ReviewService::class);
            $service->expects($this->never())->method('groups');
            $service->expects($this->never())->method('members');
            $controller = $this->controller($uid, false, $service);
            $this->assertSame(403, $controller->index()->getStatus());
            $this->assertSame(403, $controller->groups()->getStatus());
            $this->assertSame(403, $controller->members(str_repeat('a', 64))->getStatus());
        }
    }

    public function testAdminReceivesBoundedDefaultPages(): void
    {
        $service = $this->createMock(ReviewService::class);
        $service->expects($this->once())->method('groups')->with('', 25)->willReturn(['items' => [], 'nextCursor' => null]);
        $service->expects($this->once())->method('members')->with(str_repeat('a', 64), 0, 50)->willReturn(['items' => [], 'nextCursor' => null]);
        $controller = $this->controller('admin', true, $service);
        $this->assertSame(200, $controller->groups()->getStatus());
        $this->assertSame(200, $controller->members(str_repeat('a', 64))->getStatus());
    }

    public function testRejectsMalformedCursorsHashesAndPageSizesBeforeReading(): void
    {
        $service = $this->createMock(ReviewService::class);
        $service->expects($this->never())->method('groups');
        $service->expects($this->never())->method('members');
        $controller = $this->controller('admin', true, $service);
        foreach ([0, -1, 101] as $limit) {
            $this->assertSame(400, $controller->groups('', $limit)->getStatus());
        }
        $this->assertSame(400, $controller->groups('not-a-hash')->getStatus());
        $this->assertSame(400, $controller->members('../other')->getStatus());
        $this->assertSame(400, $controller->members(str_repeat('a', 64), -1)->getStatus());
    }

    public function testFrameworkAdminRequirementIsNotOptedOut(): void
    {
        foreach (['index', 'groups', 'members'] as $method) {
            $doc = (new \ReflectionMethod(ReviewController::class, $method))->getDocComment() ?: '';
            $this->assertStringNotContainsString('@NoAdminRequired', $doc);
            $this->assertStringNotContainsString('@PublicPage', $doc);
        }
    }
    public function testScopeFiltersAreForwardedAndMalformedPathsRejected(): void {
        $service = $this->createMock(ReviewService::class);
        $service->expects($this->once())->method('groups')->with('',25,'alice','/alice/files/photos')->willReturn(['items'=>[], 'nextCursor'=>null]);
        $controller=$this->controller('admin',true,$service);
        $this->assertSame(200,$controller->groups('',25,'alice','/alice/files/photos')->getStatus());
        foreach (['relative/path','/alice/../bob',"/alice/\0bad"] as $path) {
            $this->assertSame(400,$controller->groups('',25,'alice',$path)->getStatus());
        }
    }
    public function testCurrentReferenceRequiresAdminAndDoesNotUseHistoricalData(): void {
        foreach ([null,'reader'] as $uid) {
            $service=$this->createMock(ReviewService::class);$service->expects($this->never())->method('reference');
            $this->assertSame(403,$this->controller($uid,false,$service)->reference(7)->getStatus());
        }
        $service=$this->createMock(ReviewService::class);$service->expects($this->exactly(2))->method('reference')->withConsecutive([7],[8])->willReturnOnConsecutiveCalls(['id'=>7,'indexPath'=>'/alice/files/current.png','availability'=>'unavailable'],null);
        $controller=$this->controller('admin',true,$service);
        $this->assertSame(400,$controller->reference(0)->getStatus());
        $response=$controller->reference(7);$this->assertSame(200,$response->getStatus());
        $this->assertSame('/alice/files/current.png',$response->getData()['indexPath']);
        $this->assertSame(404,$controller->reference(8)->getStatus());
    }

    public function testMissingFindingsRequireAdminAndBoundedValidFilters(): void
    {
        foreach ([null,'reader'] as $uid) {
            $service=$this->createMock(ReviewService::class);
            $this->assertSame(403,$this->controller($uid,false,$service)->missingFindings()->getStatus());
        }
        $service=$this->createMock(ReviewService::class);
        $service->expects($this->once())->method('missingFindings')->with(7,25,'alice','/alice/files','image/png')->willReturn(['items'=>[],'nextCursor'=>null]);
        $c=$this->controller('admin',true,$service);
        foreach ([[-1,25,'','',''],[0,101,'','',''],[0,0,'','',''],[0,25,'','relative',''],[0,25,'','/a/../b',''],[0,25,'','','image/*'],[0,25,'','','not-a-mime'],[0,25,str_repeat('x',256),'','']] as $args)
            $this->assertSame(400,$c->missingFindings(...$args)->getStatus());
        $this->assertSame(200,$c->missingFindings(7,25,'alice','/alice/files','image/png')->getStatus());
    }
}