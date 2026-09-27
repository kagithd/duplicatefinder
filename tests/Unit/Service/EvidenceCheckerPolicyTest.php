<?php
namespace OCA\DuplicateFinder\Tests\Unit\Service;

use OCA\DuplicateFinder\Service\EvidenceCheckerPolicy;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class EvidenceCheckerPolicyTest extends TestCase
{
    public function testCheckerRulesAndDecoderMustHaveExplicitCurrentPolicy(): void
    {
        $report = ['checker' => ['id' => 'file_review.image', 'version' => '1', 'ruleVersion' => '1'],
            'decoder' => ['name' => 'Pillow', 'version' => '11']];
        foreach (['{}' => 'unverifiable', 'invalid' => 'unverifiable',
            '{"file_review.image":{"version":"2","ruleVersion":"1"}}' => 'unverifiable',
            '{"file_review.image":{"version":"1","ruleVersion":"1"}}' => 'unverifiable',
            '{"file_review.image":{"version":"2","ruleVersion":"1","decoder":{"name":"Pillow","version":"11"}}}' => 'checker_outdated',
            '{"file_review.image":{"version":"1","ruleVersion":"2","decoder":{"name":"Pillow","version":"11"}}}' => 'checker_outdated',
            '{"file_review.image":{"version":"1","ruleVersion":"1","decoder":{"name":"Pillow","version":"12"}}}' => 'checker_outdated',
            '{"file_review.image":{"version":"1","ruleVersion":"1","decoder":{"name":"Other","version":"11"}}}' => 'unverifiable',
            '{"file_review.image":{"version":"1","ruleVersion":"1","decoder":{"name":"Pillow","version":"11"}}}' => 'current'] as $json => $expected) {
            $config = $this->createMock(IConfig::class);
            $config->method('getAppValue')->with('duplicatefinder', 'evidence_checkers', '{}')->willReturn($json);
            $this->assertSame($expected, (new EvidenceCheckerPolicy($config))->evaluate($report));
        }
    }
}
