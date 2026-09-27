<?php
namespace OCA\DuplicateFinder\Controller;

use OCA\DuplicateFinder\Service\PreviewArtifactService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/** Authenticated admin and CSRF protected. No HTTP artifact writer. */
class PreviewArtifactController extends Controller
{
    private IUserSession $session;
    private IGroupManager $groups;
    private PreviewArtifactService $service;

    public function __construct(string $appName, IRequest $request, IUserSession $session, IGroupManager $groups, PreviewArtifactService $service)
    {
        parent::__construct($appName, $request);
        $this->session = $session;
        $this->groups = $groups;
        $this->service = $service;
    }

    public function getPreview(int $appRef, int $evidenceId): DataResponse
    {
        $user = $this->session->getUser();
        if ($user === null || !$this->groups->isAdmin($user->getUID())) return new DataResponse([], 403);
        if ($appRef < 1 || $evidenceId < 1 || $evidenceId === PHP_INT_MAX) {
            return new DataResponse(['error' => 'Invalid preview reference'], 400);
        }
        $artifact = $this->service->getPreview($appRef, $evidenceId);
        return $artifact === null ? new DataResponse(['error' => 'Preview unavailable'], 404) : new DataResponse($artifact);
    }
}
