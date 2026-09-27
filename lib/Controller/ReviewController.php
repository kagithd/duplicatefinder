<?php

namespace OCA\DuplicateFinder\Controller;

use OCA\DuplicateFinder\Service\ReviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/** Read-only global review. Intentionally retains Nextcloud's admin middleware. */
class ReviewController extends Controller
{
    private IUserSession $session;
    private IGroupManager $groups;
    private ReviewService $service;

    public function __construct(string $appName, IRequest $request, IUserSession $session, IGroupManager $groups, ReviewService $service)
    {
        parent::__construct($appName, $request);
        $this->session = $session;
        $this->groups = $groups;
        $this->service = $service;
    }

    private function isAdmin(): bool
    {
        $user = $this->session->getUser();
        return $user !== null && $this->groups->isAdmin($user->getUID());
    }

    /**
     * @NoCSRFRequired
     */
    public function index()
    {
        if (!$this->isAdmin()) {
            return new DataResponse([], 403);
        }
        return new TemplateResponse($this->appName, 'Review');
    }

    public function groups(string $cursor = '', int $limit = 25, string $owner = '', string $folder = ''): DataResponse
    {
        if (!$this->isAdmin()) {
            return new DataResponse([], 403);
        }
        if ($limit < 1 || $limit > 100 || ($cursor !== '' && !preg_match('/\A[a-f0-9]{64}\z/', $cursor))) {
            return new DataResponse(['error' => 'Invalid page parameters'], 400);
        }
        if (strlen($owner) > 255 || strpos($owner, "\0") !== false || strlen($folder) > 4096 ||
            strpos($folder, "\0") !== false || ($folder !== '' && (!str_starts_with($folder, '/') ||
            in_array('..', explode('/', $folder), true) || in_array('.', explode('/', $folder), true)))) {
            return new DataResponse(['error' => 'Invalid scope filters'], 400);
        }
        return new DataResponse($this->service->groups($cursor, $limit, $owner, $folder));
    }

    public function missingFindings(int $cursor = 0, int $pageSize = 25, string $owner = '', string $folder = '', string $mime = ''): DataResponse
    {
        if (!$this->isAdmin()) return new DataResponse([], 403);
        if ($cursor < 0 || $pageSize < 1 || $pageSize > 100 || strlen($owner) > 255 ||
            strpos($owner, "\0") !== false || strlen($folder) > 4096 || strpos($folder, "\0") !== false ||
            ($folder !== '' && (!str_starts_with($folder, '/') || in_array('..', explode('/', $folder), true) || in_array('.', explode('/', $folder), true))) ||
            strlen($mime) > 200 || ($mime !== '' && !preg_match('~\A[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+\z~', $mime))) {
            return new DataResponse(['error' => 'Invalid missing-finding filters'], 400);
        }
        return new DataResponse($this->service->missingFindings($cursor, $pageSize, $owner, $folder, $mime));
    }

    public function reference(int $appRef): DataResponse
    {
        if (!$this->isAdmin()) return new DataResponse([], 403);
        if ($appRef < 1) return new DataResponse(['error' => 'Invalid reference'], 400);
        $item = $this->service->reference($appRef);
        return $item === null ? new DataResponse(['error' => 'Reference not found'], 404) : new DataResponse($item);
    }
    public function members(string $hash, int $cursor = 0, int $limit = 50): DataResponse
    {
        if (!$this->isAdmin()) {
            return new DataResponse([], 403);
        }
        if ($limit < 1 || $limit > 100 || $cursor < 0 || !preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
            return new DataResponse(['error' => 'Invalid page parameters'], 400);
        }
        return new DataResponse($this->service->members($hash, $cursor, $limit));
    }
}
