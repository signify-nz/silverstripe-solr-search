<?php

use Firesphere\SolrSearch\Jobs\FullSolrIndexJob;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;


class FullSolrIndexTask extends BuildTask
{
    /**
     * URLSegment of this task
     *
     * @var string
     */
    protected static string $commandName = 'FullSolrIndexTask';
    /**
     * @var string $title
     * Shown in the overview on the {@link TaskRunner}
     * HTML or CLI interface. Should be short and concise, no HTML allowed.
     */
    protected string $title = 'Full Solr Index update';
    /**
     * @var string $description Describe the implications the task has,
     * and the changes it makes. Accepts HTML formatting.
     */
    protected static string $description = 'Add or update documents to an existing Solr core. Clears any existing data from index first.';

    /**
     *  {@inheritDoc}
     */
    public function execute(InputInterface $input, PolyOutput $output): int
    {
        $queuedJobService = Injector::inst()->get(QueuedJobService::class);
        $solrIndexJob = Injector::inst()->create(FullSolrIndexJob::class);

        $id = $queuedJobService->queueJob($solrIndexJob);
        $output->writeln("Solr Index Job added to job queue with ID: " . $id . "\n");
        if (!Director::is_cli()) {
            $output->writeln("Visit <a href=\"/admin/queuedjobs\">queued jobs admin</a> to see job status \n");
        }

        return Command::SUCCESS;
    }
}
