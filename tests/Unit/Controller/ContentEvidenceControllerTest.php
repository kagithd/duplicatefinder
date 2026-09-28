<?php
namespace OCA\DuplicateFinder\Tests\Unit\Controller;

use OCA\DuplicateFinder\Controller\ContentEvidenceController;
use OCA\DuplicateFinder\Service\ContentEvidenceService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

class ContentEvidenceControllerTest extends TestCase
{
    private function controller(?string $uid, bool $admin, $service): ContentEvidenceController
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $session->method('getUser')->willReturn($user);
        }
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($admin);
        return new ContentEvidenceController('duplicatefinder', $this->createMock(IRequest::class), $session, $groups, $service);
    }

    public function testOnlyAdminCanReadHistory(): void
    {
        foreach ([null, 'reader'] as $uid) {
            $service = $this->createMock(ContentEvidenceService::class);
            $service->expects($this->never())->method('history');
            $this->assertSame(403, $this->controller($uid, false, $service)->history(7)->getStatus());
        }
        $service = $this->createMock(ContentEvidenceService::class);
        $service->expects($this->once())->method('history')->with(7, 0, 25)->willReturn(['items' => [], 'nextCursor' => null]);
        $this->assertSame(200, $this->controller('admin', true, $service)->history(7)->getStatus());
    }

    public function testInvalidBoundsNeverReadHistory(): void
    {
        $service = $this->createMock(ContentEvidenceService::class);
        $service->expects($this->never())->method('history');
        $controller = $this->controller('admin', true, $service);
        foreach ([[0, 0, 25], [7, -1, 25], [7, 0, 0], [7, 0, 101]] as $args) {
            $this->assertSame(400, $controller->history(...$args)->getStatus());
        }
    }
}
