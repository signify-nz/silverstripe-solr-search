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
use SilverStripe\PolyExecution\PolyOutput;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

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
    protected static string $commandName = 'SolrIndexTask';
    /**
     * @var string $title
     * Shown in the overview on the {@link TaskRunner}
     * HTML or CLI interface. Should be short and concise, no HTML allowed.
     */
    protected string $title = 'Solr Index update';
    /**
     * @var string $description Describe the implications the task has,
     * and the changes it makes. Accepts HTML formatting.
     *
     */
    protected static string $description = 'Add or update documents to an existing Solr core.';

    /**
     *  {@inheritDoc}
     */
    public function execute(InputInterface $input, PolyOutput $output): int
    {
        $queuedJobService = Injector::inst()->get(QueuedJobService::class);
        $solrIndexJob = Injector::inst()->create(SolrIndexJob::class);

        $id = $queuedJobService->queueJob($solrIndexJob);
        $output->writeForHtml("Solr Index Job added to job queue with ID: $id<br>");
        if (!Director::is_cli()) {
            $output->writeForHtml("Visit <a href=\"/admin/queuedjobs\">queued jobs admin</a> to see job status<br>");
        }

        return Command::SUCCESS;
    }
}
