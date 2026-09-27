<?php
namespace OCA\DuplicateFinder\Tests\Integration;
use OC\AppFramework\Utility\ControllerMethodReflector;
use OCA\DuplicateFinder\Controller\CheckJobController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
class CheckJobAnnotationsTest extends TestCase {
    public function testQueueEndpointsRetainRealMiddlewareProtection(): void {
        if (!class_exists(ControllerMethodReflector::class)) $this->markTestSkipped('Nextcloud core required');
        foreach (['create','listing','get','cancel'] as $method) {
            $reflector=new ControllerMethodReflector(new NullLogger());
            $reflector->reflect(CheckJobController::class,$method);
            foreach (['NoCSRFRequired','NoAdminRequired','PublicPage'] as $exemption) $this->assertFalse($reflector->hasAnnotation($exemption));
        }
    }
}
