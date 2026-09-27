<?php
namespace OCA\DuplicateFinder\Controller;
use OCA\DuplicateFinder\Service\EvidenceSearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;use OCP\IUserSession;use OCP\IGroupManager;

/** Administrative read endpoint; retains default authentication and CSRF checks. */
class EvidenceSearchController extends Controller {
    public function __construct(string $appName,IRequest $request,private IUserSession $session,private IGroupManager $groups,private EvidenceSearchService $service) {
        parent::__construct($appName,$request);
    }
    public function search(int $cursor=0,int $limit=25,string $status='',string $format=''):DataResponse {
        $user=$this->session->getUser();
        if($user===null || !$this->groups->isAdmin($user->getUID()))return new DataResponse([],403);
        try {return new DataResponse($this->service->search($cursor,$limit,$status,$format));}
        catch(\InvalidArgumentException $e){return new DataResponse(['error'=>'Invalid evidence search'],400);}
    }
}