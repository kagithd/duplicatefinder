<?php
namespace OCA\DuplicateFinder\Controller;
use OCA\DuplicateFinder\Service\ReviewGroupService;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\{IRequest,IUserSession,IGroupManager};

/** Admin and CSRF middleware remain enabled, including for this read-only route. */
class ReviewGroupController extends Controller {
    public function __construct(string $appName, IRequest $request, private IUserSession $session,
        private IGroupManager $groups, private ReviewGroupService $service) {
        parent::__construct($appName,$request);
    }
    public function page(int $appRef, int $depth=0, int $shareOffset=0, string $shareId="", int $offset=0, int $pageSize=25): DataResponse {
        $user=$this->session->getUser();
        if ($user === null || !$this->groups->isAdmin($user->getUID())) return new DataResponse([],403);
        try { return new DataResponse($this->service->page($appRef,$depth,$shareOffset,$shareId,$offset,$pageSize)); }
        catch (EvidenceConflictException $e) { return new DataResponse(['error'=>'Group membership context changed or is unavailable'],409); }
        catch (\InvalidArgumentException $e) { return new DataResponse(['error'=>'Invalid membership page'],400); }
        catch (\Throwable $e) { return new DataResponse(['error'=>'Membership observation unavailable'],503); }
    }
}
