<?php
namespace OCA\DuplicateFinder\Tests\Unit\Controller;

use OCA\DuplicateFinder\Controller\PreviewArtifactController;
use OCA\DuplicateFinder\Service\PreviewArtifactService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

class PreviewArtifactControllerTest extends TestCase
{
    private function controller(?string $uid, bool $admin, $service): PreviewArtifactController
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $session->method('getUser')->willReturn($user);
        }
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($admin);
        return new PreviewArtifactController('duplicatefinder', $this->createMock(IRequest::class), $session, $groups, $service);
    }

    public function testOnlyAdminCanReadHistory(): void
    {
        foreach ([null, 'reader'] as $uid) {
            $service = $this->createMock(PreviewArtifactService::class);
            $service->expects($this->never())->method('getPreview');
            $this->assertSame(403, $this->controller($uid, false, $service)->getPreview(7, 19)->getStatus());
        }
        $service = $this->createMock(PreviewArtifactService::class);
        $service->expects($this->once())->method('getPreview')->with(7, 19)->willReturn(['record' => []]);
        $this->assertSame(200, $this->controller('admin', true, $service)->getPreview(7, 19)->getStatus());
    }

    public function testInvalidBoundsNeverReadHistory(): void
    {
        $service = $this->createMock(PreviewArtifactService::class);
        $service->expects($this->never())->method('getPreview');
        $controller = $this->controller('admin', true, $service);
        foreach ([[0, 19], [7, 0], [7, -1]] as $args) {
            $this->assertSame(400, $controller->getPreview(...$args)->getStatus());
        }
    }
    public function testMissingArtifactReturns404(): void
    {
        $service = $this->createMock(PreviewArtifactService::class);
        $service->method('getPreview')->willReturn(null);
        $this->assertSame(404, $this->controller('admin', true, $service)->getPreview(7, 19)->getStatus());
    }}
