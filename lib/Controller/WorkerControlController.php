<?php
namespace OCA\DuplicateFinder\Controller;
use OCA\DuplicateFinder\Service\WorkerControlService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\{IRequest,IUserSession,IGroupManager};

/** Framework admin and CSRF checks remain enabled for every operation. */
class WorkerControlController extends Controller {
    private IUserSession $session;
    private IGroupManager $groups;
    private WorkerControlService $service;
    public function __construct(string $appName,IRequest $request,IUserSession $session,IGroupManager $groups,WorkerControlService $service) {
        parent::__construct($appName,$request);$this->session=$session;$this->groups=$groups;$this->service=$service;
    }
    private function respond(string $action,?string $runId=null): DataResponse {
        $user=$this->session->getUser();
        if($user===null||!$this->groups->isAdmin($user->getUID())) return new DataResponse([],403);
        try {
            $result=$this->service->control($action,$runId);
            $status=($result['available']??false)?200:503;
            if(($result['error']??null)==='generation_conflict') $status=409;
            return new DataResponse($result,$status);
        } catch(\InvalidArgumentException $e) {
            return new DataResponse(['error'=>'invalid_worker_request'],400);
        }
    }
    public function status(): DataResponse { return $this->respond('status'); }
    public function start(): DataResponse { return $this->respond('start'); }
    public function stop(string $runId): DataResponse { return $this->respond('stop',$runId); }
}
