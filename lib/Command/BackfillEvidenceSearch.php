<?php
namespace OCA\DuplicateFinder\Command;
use OCA\DuplicateFinder\Db\EvidenceMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillEvidenceSearch extends Command {
    public function __construct(private EvidenceMapper $mapper) {parent::__construct();}
    protected function configure():void {
        $this->setName('duplicates:review-index-evidence')
            ->setDescription('Populate historical evidence search labels without changing reports or files')
            ->addOption('batch-size',null,InputOption::VALUE_REQUIRED,'Rows per bounded batch (1..100)','100')
            ->addOption('max-batches',null,InputOption::VALUE_REQUIRED,'Maximum batches this invocation (1..10000)','10');
    }
    protected function execute(InputInterface $input,OutputInterface $output):int {
        $batch=filter_var($input->getOption('batch-size'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        $max=filter_var($input->getOption('max-batches'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
        if($batch===false || $max===false){$output->writeln('<error>Invalid batch bounds</error>');return 2;}
        $visited=0;$batches=0;
        for($i=0;$i<$max;$i++){
            $count=$this->mapper->backfillSearchMetadata($batch);$visited+=$count;$batches++;
            if($count===0)break;
        }
        $output->writeln(json_encode(['rowsVisited'=>$visited,'batches'=>$batches,
            'metadataComplete'=>!$this->mapper->hasUnprojectedEvidence(),
            'scope'=>'historical_report_labels_only'],JSON_THROW_ON_ERROR));
        return 0;
    }
}