<?php
namespace OCA\DuplicateFinder\Tests\Unit\Controller;

use OCA\DuplicateFinder\Controller\DetailArtifactController;
use OCA\DuplicateFinder\Service\DetailArtifactService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

class DetailArtifactControllerTest extends TestCase
{
    private function controller(?string $uid, bool $admin, $service): DetailArtifactController
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $session->method('getUser')->willReturn($user);
        }
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($admin);
        return new DetailArtifactController('duplicatefinder', $this->createMock(IRequest::class), $session, $groups, $service);
    }

    public function testOnlyAdminCanReadHistory(): void
    {
        foreach ([null, 'reader'] as $uid) {
            $service = $this->createMock(DetailArtifactService::class);
            $service->expects($this->never())->method('getDetail');
            $this->assertSame(403, $this->controller($uid, false, $service)->getDetail(7, 19, 31)->getStatus());
        }
        $service = $this->createMock(DetailArtifactService::class);
        $service->expects($this->once())->method('getDetail')->with(7, 19, 31)->willReturn(['record' => []]);
        $this->assertSame(200, $this->controller('admin', true, $service)->getDetail(7, 19, 31)->getStatus());
    }

    public function testInvalidBoundsNeverReadHistory(): void
    {
        $service = $this->createMock(DetailArtifactService::class);
        $service->expects($this->never())->method('getDetail');
        $controller = $this->controller('admin', true, $service);
        foreach ([[0, 19, 31], [7, 0, 31], [7, -1, 31], [7, 19, 0], [7, 19, -1], [7, 19, PHP_INT_MAX]] as $args) {
            $this->assertSame(400, $controller->getDetail(...$args)->getStatus());
        }
    }
    public function testMissingArtifactReturns404(): void
    {
        $service = $this->createMock(DetailArtifactService::class);
        $service->method('getDetail')->willReturn(null);
        $this->assertSame(404, $this->controller('admin', true, $service)->getDetail(7, 19, 31)->getStatus());
    }}
