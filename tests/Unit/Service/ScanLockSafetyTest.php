<?php

declare(strict_types=1);

namespace OCA\DuplicateFinder\Tests\Unit\Service;

use OCA\DuplicateFinder\Service\FileInfoService;
use OCA\DuplicateFinder\Service\FolderService;
use OCA\DuplicateFinder\Utils\ScannerUtil;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ScanLockSafetyTest extends TestCase
{
    public function scanOutcomes(): array
    {
        return ['successful scan' => [false], 'locked scan' => [true]];
    }

    /** @dataProvider scanOutcomes */
    public function testScanPreservesLocksAndReportsOutcome(bool $locked): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getPath')->willReturn('/synthetic/files');
        $folders = $this->createMock(FolderService::class);
        $folders->method('getUserFolder')->with('synthetic')->willReturn($folder);
        $failure = new LockedException('/synthetic/files/locked.txt');
        $scanner = $this->createMock(ScannerUtil::class);
        $scan = $scanner->expects($this->once())->method('scan')
            ->with('synthetic', '/synthetic/files');
        if ($locked) {
            $scan->willThrowException($failure);
        }
        $locks = $this->createMock(ILockingProvider::class);
        $locks->expects($this->never())->method('releaseAll');
        $locks->expects($this->never())->method('releaseLock');
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('prepare');
        $reflection = new \ReflectionClass(FileInfoService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach (['folderService' => $folders, 'scannerUtil' => $scanner,
            'lockingProvider' => $locks, 'connection' => $db, 'logger' => new NullLogger()] as $name => $dependency) {
            $reflection->getProperty($name)->setValue($service, $dependency);
        }
        if ($locked) {
            $this->expectExceptionObject($failure);
        }
        $service->scanFiles('synthetic');
    }
}
