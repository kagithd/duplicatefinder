<?php
namespace OCA\DuplicateFinder\Tests\Unit\Controller;

use OCA\DuplicateFinder\Controller\EvidenceController;
use OCA\DuplicateFinder\Service\EvidenceService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

class EvidenceControllerTest extends TestCase
{
    private function controller(?string $uid, bool $admin, $service): EvidenceController
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $session->method('getUser')->willReturn($user);
        }
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($admin);
        return new EvidenceController('duplicatefinder', $this->createMock(IRequest::class), $session, $groups, $service);
    }

    public function testOnlyAdminCanReadHistory(): void
    {
        foreach ([null, 'reader'] as $uid) {
            $service = $this->createMock(EvidenceService::class);
            $service->expects($this->never())->method('getHistory');
            $this->assertSame(403, $this->controller($uid, false, $service)->history(7)->getStatus());
        }
        $service = $this->createMock(EvidenceService::class);
        $service->expects($this->once())->method('getHistory')->with(7, 0, 25)->willReturn(['items' => [], 'nextCursor' => null]);
        $this->assertSame(200, $this->controller('admin', true, $service)->history(7)->getStatus());
    }

    public function testInvalidBoundsNeverReadHistory(): void
    {
        $service = $this->createMock(EvidenceService::class);
        $service->expects($this->never())->method('getHistory');
        $controller = $this->controller('admin', true, $service);
        foreach ([[0, 0, 25], [7, -1, 25], [7, 0, 0], [7, 0, 101]] as $args) {
            $this->assertSame(400, $controller->history(...$args)->getStatus());
        }
    }
}
