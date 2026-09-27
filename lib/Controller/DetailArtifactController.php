<?php
namespace OCA\DuplicateFinder\Controller;

use OCA\DuplicateFinder\Service\DetailArtifactService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/** Authenticated admin and CSRF protected. No HTTP artifact writer. */
class DetailArtifactController extends Controller
{
    private IUserSession $session;
    private IGroupManager $groups;
    private DetailArtifactService $service;

    public function __construct(string $appName, IRequest $request, IUserSession $session, IGroupManager $groups, DetailArtifactService $service)
    {
        parent::__construct($appName, $request);
        $this->session = $session;
        $this->groups = $groups;
        $this->service = $service;
    }

    public function getDetail(int $appRef, int $evidenceId, int $detailId): DataResponse
    {
        $user = $this->session->getUser();
        if ($user === null || !$this->groups->isAdmin($user->getUID())) return new DataResponse([], 403);
        if ($appRef < 1 || $evidenceId < 1 || $evidenceId === PHP_INT_MAX || $detailId < 1 || $detailId === PHP_INT_MAX) {
            return new DataResponse(['error' => 'Invalid detail reference'], 400);
        }
        $artifact = $this->service->getDetail($appRef, $evidenceId, $detailId);
        return $artifact === null ? new DataResponse(['error' => 'Detail unavailable'], 404) : new DataResponse($artifact);
    }
}
