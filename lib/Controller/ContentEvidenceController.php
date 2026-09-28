<?php
namespace OCA\DuplicateFinder\Controller;

use OCA\DuplicateFinder\Service\ContentEvidenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/** No public, non-admin or CSRF exemptions. There is deliberately no writer. */
class ContentEvidenceController extends Controller
{
    private IUserSession $session;
    private IGroupManager $groups;
    private ContentEvidenceService $service;

    public function __construct(string $appName, IRequest $request, IUserSession $session, IGroupManager $groups, ContentEvidenceService $service)
    {
        parent::__construct($appName, $request);
        $this->session = $session;
        $this->groups = $groups;
        $this->service = $service;
    }

    public function history(int $appRef, int $cursor = 0, int $pageSize = 25): DataResponse
    {
        $user = $this->session->getUser();
        if ($user === null || !$this->groups->isAdmin($user->getUID())) return new DataResponse([], 403);
        if ($appRef < 1 || $cursor < 0 || $pageSize < 1 || $pageSize > 100) {
            return new DataResponse(['error' => 'Invalid history page'], 400);
        }
        return new DataResponse($this->service->history($appRef, $cursor, $pageSize));
    }
}
