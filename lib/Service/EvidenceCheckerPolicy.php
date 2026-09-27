<?php
namespace OCA\DuplicateFinder\Service;

use OCP\IConfig;

/** Server-managed policy; never accepts versions selected in an HTTP request. */
class EvidenceCheckerPolicy
{
    private IConfig $config;
    public function __construct(IConfig $config) { $this->config = $config; }

    public function evaluate(array $report): string
    {
        $policies = json_decode($this->config->getAppValue('duplicatefinder', 'evidence_checkers', '{}'), true);
        $checker = $report['checker'] ?? [];
        $policy = is_array($policies) ? ($policies[$checker['id'] ?? ''] ?? null) : null;
        if (!is_array($policy)) return 'unverifiable';
        foreach (['version', 'ruleVersion'] as $field) {
            if (!is_string($policy[$field] ?? null) || $policy[$field] === '' || !is_string($checker[$field] ?? null)) {
                return 'unverifiable';
            }
        }
        $decoder = $report['decoder'] ?? null;
        $expected = $policy['decoder'] ?? null;
        if (!is_array($decoder) || !is_array($expected)) return 'unverifiable';
        foreach (['name', 'version'] as $field) {
            if (!is_string($expected[$field] ?? null) || $expected[$field] === '' || !is_string($decoder[$field] ?? null) || $decoder[$field] === '') {
                return 'unverifiable';
            }
        }
        if ($decoder['name'] !== $expected['name']) return 'unverifiable';
        foreach (['version', 'ruleVersion'] as $field) {
            if ($policy[$field] !== $checker[$field]) return 'checker_outdated';
        }
        return $decoder['version'] === $expected['version'] ? 'current' : 'checker_outdated';
    }
}
