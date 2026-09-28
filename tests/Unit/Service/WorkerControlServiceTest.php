<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;
use OCA\DuplicateFinder\Service\WorkerControlService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class WorkerControlServiceTest extends TestCase {
    private function service(string $path, ?array $response=null): WorkerControlService {
        $config=$this->createMock(IConfig::class);
        $config->method('getSystemValue')->with('duplicatefinder_worker_control_socket','')->willReturn($path);
        $service=$this->getMockBuilder(WorkerControlService::class)->setConstructorArgs([$config])->onlyMethods(['exchange'])->getMock();
        if($response===null) $service->expects($this->never())->method('exchange');
        else $service->method('exchange')->willReturn($response);
        return $service;
    }
    public function testMissingConfigurationNeverClaimsWorkerIsStopped(): void {
        $s=$this->service('');
        foreach(['status','start','stop'] as $action) {
            $r=$s->control($action,$action==='stop'?str_repeat('a',32):null);
            $this->assertFalse($r['available']);$this->assertArrayNotHasKey('state',$r);
        }
    }
    public function testValidatedRunningStatusPreservesGeneration(): void {
        $status=['state'=>'running','runId'=>str_repeat('a',32),'exitCode'=>null];
        $s=$this->service('/private/control.sock',['ok'=>true,'status'=>$status]);
        $this->assertSame(['available'=>true]+$status,$s->control('start'));
    }
    public function testMalformedStatusCannotBeDisplayedAsHealthy(): void {
        foreach([
            ['state'=>'running','runId'=>null,'exitCode'=>null],
            ['state'=>'finished','runId'=>str_repeat('a',32),'exitCode'=>7],
            ['state'=>'idle','runId'=>null,'exitCode'=>0],
            ['state'=>'failed','runId'=>str_repeat('a',32),'exitCode'=>0],
        ] as $status) {
            $r=$this->service('/private/control.sock',['ok'=>true,'status'=>$status])->control('status');
            $this->assertFalse($r['available']);$this->assertArrayNotHasKey('state',$r);
        }
    }
    public function testStaleStopHasExplicitConflict(): void {
        $s=$this->service('/private/control.sock',['ok'=>false,'error'=>'generation_conflict']);
        $this->assertSame(['available'=>true,'error'=>'generation_conflict'],$s->control('stop',str_repeat('a',32)));
    }
    public function testInvalidActionNeverReachesSocket(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service('/private/control.sock')->control('execute');
    }
    public function testInvalidRunIdentityNeverReachesSocket(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service('/private/control.sock')->control('stop','old');
    }
}
