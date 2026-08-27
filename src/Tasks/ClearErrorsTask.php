<?php

/**
 * Class ClearErrorsTask|Firesphere\SolrSearch\Tasks\ClearErrorsTask Clear out errors from the database to
 * declutter the CMS.
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 */

namespace Firesphere\SolrSearch\Tasks;

use Firesphere\SolrSearch\Models\SolrLog;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Class ClearErrorsTask
 *
 * Clear out errors from the database to declutter the CMS.
 * Consider running this task through the CLI, as it may take some time (especially if it has not been run before).
 *
 * @package Firesphere\Solr\Search
 */
class ClearErrorsTask extends BuildTask
{
    /**
     * @var string URLSegment
     */
    protected static string $commandName = 'SolrClearErrorsTask';
    /**
     * @var string Title
     */
    protected string $title = 'Clear out all errors from Solr in the database';
    /**
     * @var string Description
     */
    protected static string $description = 'Remove all errors in the database that are related to Solr indexing/configuring etc.';

    /**
     * Delete entries from the SolrLog table
     * @inheritDoc
     */
    public function execute(InputInterface $input, PolyOutput $output): int
    {
        $logsDeleted = SolrLog::truncateLogs();
        $output->writeln('Deleted ' . $logsDeleted . ' logs from the database.');

        return Command::SUCCESS;
    }
}
