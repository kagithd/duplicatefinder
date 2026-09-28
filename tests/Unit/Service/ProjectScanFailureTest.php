<?php

declare(strict_types=1);

namespace OCA\DuplicateFinder\Tests\Unit\Service;

use OCA\DuplicateFinder\Db\FileDuplicateMapper;
use OCA\DuplicateFinder\Db\Project;
use OCA\DuplicateFinder\Db\ProjectMapper;
use OCA\DuplicateFinder\Service\FileInfoService;
use OCA\DuplicateFinder\Service\ProjectService;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ProjectScanFailureTest extends TestCase
{
    public function outcomes(): array
    {
        return ['first folder' => ['first'], 'second folder' => ['second'],
            'discovery' => ['discovery'], 'success' => ['success'], 'matching results' => ['matches'], 'later file lookup' => ['lookup']];
    }

    /** @dataProvider outcomes */
    public function testPublishesResultsOnlyAfterSuccessfulScanAndDiscovery(string $outcome): void
    {
        $events = [];
        $failure = new \RuntimeException('Synthetic scan failure');
        $project = new Project();
        $project->setId(7);
        $project->setName('Synthetic');
        $mapper = $this->createMock(ProjectMapper::class);
        $mapper->method('find')->with(7, 'synthetic')->willReturn($project);
        $mapper->method('getFolders')->with(7)->willReturn(['/a', '/b']);
        $mapper->method('removeDuplicates')->willReturnCallback(function () use (&$events): void { $events[] = 'remove'; });
        $mapper->method('updateLastScan')->willReturnCallback(function () use (&$events): void { $events[] = 'timestamp'; });
        $mapper->method('addDuplicate')->willReturnCallback(function ($projectId, $duplicateId) use (&$events): void {
            $this->assertSame(7, $projectId);
            $events[] = 'add:' . $duplicateId;
        });
        $files = $this->createMock(FileInfoService::class);
        $files->method('scanFiles')->willReturnCallback(function ($user, $path) use (&$events, $failure, $outcome): void {
            $this->assertSame('synthetic', $user);
            $events[] = 'scan:' . $path;
            if (($outcome === 'first' && $path === '/a') || ($outcome === 'second' && $path === '/b')) {
                throw $failure;
            }
        });
        $duplicates = $this->createMock(FileDuplicateMapper::class);
        $duplicates->method('findDuplicatesWithFiles')->willReturnCallback(function () use (&$events, $failure, $outcome): array {
            $events[] = 'discover';
            if ($outcome === 'discovery') { throw $failure; }
            $rows = [['id' => 42, 'hash' => 'synthetic-hash', 'type' => 'new']];
            if ($outcome === 'lookup') {
                $rows[] = ['id' => 43, 'hash' => 'other-hash', 'type' => 'new'];
            }
            return in_array($outcome, ['matches', 'lookup'], true) ? $rows : [];
        });
        $duplicates->method('findFilesByHash')->willReturnCallback(function ($hash) use ($failure): array {
            if ($hash === 'other-hash') { throw $failure; }
            return [['path' => '/synthetic/files/a/one.txt'], ['path' => '/synthetic/files/b/two.txt']];
        });
        $service = new ProjectService($mapper, $duplicates, $this->createMock(IRootFolder::class), $files, 'synthetic', new NullLogger());
        $caught = null;
        try { $service->scan(7); } catch (\RuntimeException $e) { $caught = $e; }
        if (in_array($outcome, ['success', 'matches'], true)) {
            $this->assertNull($caught);
            $expected = ['scan:/a', 'scan:/b', 'discover', 'remove'];
            if ($outcome === 'matches') { $expected[] = 'add:42'; }
            $expected[] = 'timestamp';
            $this->assertSame($expected, $events);
        } else {
            $this->assertSame($failure, $caught);
            $expected = ['scan:/a'];
            if ($outcome !== 'first') { $expected[] = 'scan:/b'; }
            if (in_array($outcome, ['discovery', 'lookup'], true)) { $expected[] = 'discover'; }
            $this->assertSame($expected, $events);
        }
    }
}
