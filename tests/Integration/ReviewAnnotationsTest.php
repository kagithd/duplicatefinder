<?php

namespace OCA\DuplicateFinder\Tests\Integration;

use OC\AppFramework\Utility\ControllerMethodReflector;
use OCA\DuplicateFinder\Controller\ReviewController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Requires the real Nextcloud core reflector, not an annotation-parser mock. */
class ReviewAnnotationsTest extends TestCase
{
    public function testOnlyThePageEntrySkipsCsrfAndAllReviewRoutesRequireAdmin(): void
    {
        if (!class_exists(ControllerMethodReflector::class)) {
            $this->markTestSkipped('Nextcloud core is required for annotation integration');
        }

        foreach (['index' => true, 'groups' => false, 'members' => false] as $method => $pageEntry) {
            $reflector = new ControllerMethodReflector(new NullLogger());
            $reflector->reflect(ReviewController::class, $method);
            $this->assertSame($pageEntry, $reflector->hasAnnotation('NoCSRFRequired'), $method . ' CSRF policy');
            $this->assertFalse($reflector->hasAnnotation('NoAdminRequired'), $method . ' requires admin middleware');
            $this->assertFalse($reflector->hasAnnotation('PublicPage'), $method . ' requires authentication');
        }
    }
    public function testEvidenceHistoryRetainsAllSecurityMiddlewareChecks(): void
    {
        if (!class_exists(ControllerMethodReflector::class)) {
            $this->markTestSkipped('Nextcloud core is required for annotation integration');
        }
        $reflector = new ControllerMethodReflector(new NullLogger());
        $reflector->reflect(\OCA\DuplicateFinder\Controller\EvidenceController::class, 'history');
        foreach (['NoCSRFRequired', 'NoAdminRequired', 'PublicPage'] as $exemption) {
            $this->assertFalse($reflector->hasAnnotation($exemption), $exemption);
        }
    }
    public function testEveryPlanRouteRetainsRealMiddlewareProtection(): void
    {
        if (!class_exists(ControllerMethodReflector::class)) {
            $this->markTestSkipped('Nextcloud core is required for annotation integration');
        }
        foreach (['create', 'append', 'listing', 'revision', 'export'] as $method) {
            $reflector = new ControllerMethodReflector(new NullLogger());
            $reflector->reflect(\OCA\DuplicateFinder\Controller\PlanController::class, $method);
            foreach (['NoCSRFRequired', 'NoAdminRequired', 'PublicPage'] as $exemption) {
                $this->assertFalse($reflector->hasAnnotation($exemption), $method . ': ' . $exemption);
            }
        }
    }

    public function testPreviewReadRetainsAllRealMiddlewareChecks(): void
    {
        if (!class_exists(ControllerMethodReflector::class)) {
            $this->markTestSkipped('Nextcloud core is required for annotation integration');
        }
        $reflector = new ControllerMethodReflector(new NullLogger());
        $reflector->reflect(\OCA\DuplicateFinder\Controller\PreviewArtifactController::class, 'getPreview');
        foreach (['NoCSRFRequired', 'NoAdminRequired', 'PublicPage'] as $exemption) {
            $this->assertFalse($reflector->hasAnnotation($exemption), $exemption);
        }
    }
}
