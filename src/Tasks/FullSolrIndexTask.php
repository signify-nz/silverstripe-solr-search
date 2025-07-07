<?php

use Firesphere\SolrSearch\Jobs\FullSolrIndexJob;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\TaskRunner;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class FullSolrIndexTask extends BuildTask
{
    /**
     * URLSegment of this task
     *
     * @var string
     */
    private static $segment = 'FullSolrIndexTask';
    /**
     * @var string $title
     * Shown in the overview on the {@link TaskRunner}
     * HTML or CLI interface. Should be short and concise, no HTML allowed.
     */
    protected $title = 'Full Solr Index update';
    /**
     * @var string $description Describe the implications the task has,
     * and the changes it makes. Accepts HTML formatting.
     */
    protected $description = 'Add or update documents to an existing Solr core. Clears any existing data from index first.';

    /**
     *  {@inheritDoc}
     */
    public function run($request)
    {
        $queuedJobService = Injector::inst()->get(QueuedJobService::class);
        $solrIndexJob = Injector::inst()->create(FullSolrIndexJob::class);

        $id = $queuedJobService->queueJob($solrIndexJob);
        echo ("Solr Index Job added to job queue with ID: " . $id . "\n");
        if (!Director::is_cli()) {
            echo("Visit <a href=\"/admin/queuedjobs\">queued jobs admin</a> to see job status \n");
        }
    }
}
