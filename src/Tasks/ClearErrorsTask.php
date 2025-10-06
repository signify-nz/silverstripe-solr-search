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

/**
 * Class ClearErrorsTask
 *
 * Clear out errors from the database to declutter the CMS.
 *
 * @package Firesphere\Solr\Search
 */
class ClearErrorsTask extends BuildTask
{
    /**
     * @var string URLSegment
     */
    private static $segment = 'SolrClearErrorsTask';
    /**
     * @var string Title
     */
    protected $title = 'Clear out all errors from Solr in the database';
    /**
     * @var string Description
     */
    protected $description = 'Remove all errors in the database that are related to Solr indexing/configuring etc.';

    /**
     * Delete entries from the SolrLog table
     * @inheritDoc
     */
    public function run($request)
    {
        $logsDeleted = SolrLog::truncateLogs();
        echo('Deleted ' . $logsDeleted . ' logs from the database.');
    }
}
