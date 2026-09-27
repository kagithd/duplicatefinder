<?php
namespace OCA\DuplicateFinder\Tests\Unit\Command;
use OCA\DuplicateFinder\Command\BackfillEvidenceSearch;
use OCA\DuplicateFinder\Db\EvidenceMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
class BackfillEvidenceSearchTest extends TestCase {
    public function testStopsAtBoundAndReportsRemainingWork():void {
        $m=$this->createMock(EvidenceMapper::class);$m->expects($this->exactly(2))->method('backfillSearchMetadata')->with(3)->willReturn(3);
        $m->method('hasUnprojectedEvidence')->willReturn(true);$t=new CommandTester(new BackfillEvidenceSearch($m));
        $this->assertSame(0,$t->execute(['--batch-size'=>'3','--max-batches'=>'2']));
        $out=json_decode($t->getDisplay(),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame(6,$out['rowsVisited']);$this->assertFalse($out['metadataComplete']);
    }
    public function testRepeatAfterCompletionDoesNoWork():void {
        $m=$this->createMock(EvidenceMapper::class);$m->expects($this->once())->method('backfillSearchMetadata')->willReturn(0);
        $m->method('hasUnprojectedEvidence')->willReturn(false);$t=new CommandTester(new BackfillEvidenceSearch($m));
        $this->assertSame(0,$t->execute([]));$out=json_decode($t->getDisplay(),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame(0,$out['rowsVisited']);$this->assertTrue($out['metadataComplete']);
    }
    public function testInvalidOptionsDoNotWrite():void {
        foreach([['--batch-size'=>'101'],['--batch-size'=>'0'],['--max-batches'=>'0'],['--max-batches'=>'garbage'],['--batch-size'=>'1.5']] as $args){
            $m=$this->createMock(EvidenceMapper::class);$m->expects($this->never())->method('backfillSearchMetadata');
            $t=new CommandTester(new BackfillEvidenceSearch($m));$this->assertSame(2,$t->execute($args));
        }
    }
}