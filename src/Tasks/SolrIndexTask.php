<?php
/**
 * Class SolrIndexTask|Firesphere\SolrSearch\Tasks\SolrIndexTask Index Solr cores
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in Aug 2024
 */

namespace Firesphere\SolrSearch\Tasks;

use Firesphere\SolrSearch\Jobs\SolrIndexJob;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Class SolrIndexTask
 *
 * @description Index items to Solr through a tasks
 * @package Firesphere\Solr\Search
 */
class SolrIndexTask extends BuildTask
{
    /**
     * URLSegment of this task
     *
     * @var string
     */
    private static $segment = 'SolrIndexTask';
    /**
     * @var string $title
     * Shown in the overview on the {@link TaskRunner}
     * HTML or CLI interface. Should be short and concise, no HTML allowed.
     */
    protected $title = 'Solr Index update';
    /**
     * @var string $description Describe the implications the task has,
     * and the changes it makes. Accepts HTML formatting.
     *
     */
    protected $description = 'Add or update documents to an existing Solr core.';

    /**
     *  {@inheritDoc}
     */
    public function run($request)
    {
        $queuedJobService = Injector::inst()->get(QueuedJobService::class);
        $solrIndexJob = Injector::inst()->create(SolrIndexJob::class);

        $id = $queuedJobService->queueJob($solrIndexJob);
        echo ("Solr Index Job added to job queue with ID: " . $id . "\n");
        if (!Director::is_cli()) {
            echo("Visit <a href=\"/admin/queuedjobs\">queued jobs admin</a> to see job status \n");
        }
    }
}
