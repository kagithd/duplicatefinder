<?php
namespace OCA\DuplicateFinder\Service;

use OCP\IConfig;

/** Optional local process control. Missing connectivity never proves a stopped worker. */
class WorkerControlService {
    private IConfig $config;
    public function __construct(IConfig $config) { $this->config=$config; }

    public function control(string $action,?string $runId=null): array {
        if(!in_array($action,['status','start','stop'],true)
            || ($action==='stop' && (!is_string($runId)||!preg_match('/\A[a-f0-9]{32}\z/',$runId)))
            || ($action!=='stop' && $runId!==null)) throw new \InvalidArgumentException('Invalid worker request');
        $path=$this->config->getSystemValue('duplicatefinder_worker_control_socket','');
        if($path==='') return ['available'=>false,'reason'=>'not_configured'];
        if(!is_string($path)||$path[0]!=='/'||strlen($path)>100||str_contains($path,"\0")) {
            return ['available'=>false,'reason'=>'invalid_configuration'];
        }
        $request=['version'=>1,'action'=>$action];
        if($action==='stop') $request['runId']=$runId;
        try { $response=$this->exchange($path,$request); }
        catch(\RuntimeException|\JsonException $e) { return ['available'=>false,'reason'=>'unreachable']; }
        if(($response['ok']??null)===false && ($response['error']??null)==='generation_conflict' && $action==='stop') {
            return ['available'=>true,'error'=>'generation_conflict'];
        }
        $status=$response['status']??null;
        if(($response['ok']??null)!==true || !$this->validStatus($status)) {
            return ['available'=>false,'reason'=>'invalid_response'];
        }
        return ['available'=>true]+$status;
    }

    private function validStatus($status): bool {
        if(!is_array($status)||count($status)!==3||!array_key_exists('state',$status)
            ||!array_key_exists('runId',$status)||!array_key_exists('exitCode',$status)) return false;
        $state=$status['state'];$id=$status['runId'];$code=$status['exitCode'];
        if($state==='idle') return $id===null && $code===null;
        if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/',$id)) return false;
        if(in_array($state,['running','stopping'],true)) return $code===null;
        if(in_array($state,['finished','stopped'],true)) return $code===0;
        return $state==='failed' && ($code===null || (is_int($code) && $code!==0));
    }

    protected function exchange(string $path,array $request): array {
        $connection=@stream_socket_client('unix://'.$path,$errno,$error,2,STREAM_CLIENT_CONNECT);
        if($connection===false) throw new \RuntimeException('Worker connection unavailable');
        try {
            stream_set_timeout($connection,2);
            $message=json_encode($request,JSON_THROW_ON_ERROR)."\n";
            // Small fixed protocol message. Partial transmission is uncertain;
            // never automatically retry a potentially accepted start or stop.
            if(@fwrite($connection,$message)!==strlen($message)) throw new \RuntimeException('Worker write failed');
            $raw=@fgets($connection,4098);
            $meta=stream_get_meta_data($connection);
            if($raw===false||$meta['timed_out']||strlen($raw)>4096||!str_ends_with($raw,"\n")) {
                throw new \RuntimeException('Worker reply unavailable');
            }
            $response=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($response)) throw new \RuntimeException('Invalid worker reply');
            return $response;
        } finally { fclose($connection); }
    }
}
