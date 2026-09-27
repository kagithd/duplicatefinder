<?php
namespace OCA\DuplicateFinder\Controller;
use OCA\DuplicateFinder\Service\CheckJobService;
use OCA\DuplicateFinder\Exception\EvidenceConflictException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\{IRequest,IUserSession,IGroupManager};
/** Every endpoint requires framework admin and CSRF validation. */
class CheckJobController extends Controller {
    private IUserSession $session;
    private IGroupManager $groups;
    private CheckJobService $service;
    public function __construct(string $appName,IRequest $request,IUserSession $session,IGroupManager $groups,CheckJobService $service) {
        parent::__construct($appName,$request);$this->session=$session;$this->groups=$groups;$this->service=$service;
    }
    private function respond(callable $operation): DataResponse {
        $user=$this->session->getUser();if($user===null||!$this->groups->isAdmin($user->getUID())) return new DataResponse([],403);
        try { $record=$operation($user->getUID());return new DataResponse($record??[],$record===null?404:200); }
        catch(EvidenceConflictException $e) { return new DataResponse(['error'=>$e->getMessage()],409); }
        catch(\InvalidArgumentException|\JsonException $e) { return new DataResponse(['error'=>'Invalid original check request'],400); }
    }
    public function create(array $payload): DataResponse { return $this->respond(fn($uid)=>$this->service->create($payload,$uid)); }
    public function listing(int $cursor=0,int $limit=25): DataResponse { return $this->respond(fn()=>$this->service->listing($cursor,$limit)); }
    public function get(string $jobId): DataResponse { return $this->respond(fn()=>$this->service->get($jobId)); }
    public function cancel(string $jobId): DataResponse { return $this->respond(fn()=>$this->service->cancel($jobId)); }
}
