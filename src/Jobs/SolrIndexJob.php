<?php
/**
 * class SolrIndexJob|Firesphere\SolrSearch\Jobs\SolrIndexJob Index items from the CMS through a QueuedJob
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in June 2025
 */

namespace Firesphere\SolrSearch\Jobs;

/**
 * FullSolrIndexJob is a queued job to index all existing indexes and their classes.
 *
 * It always runs on all indexes, to make sure all indexes are up to date.
 *
 * It will not clear out any existing index data before running.
 *
 * @package Firesphere\Solr\Search
 */
class SolrIndexJob extends FullSolrIndexJob
{
    /**
     * Whether the job should clear the index before running.
     *
     * @var bool
     */
    protected $shouldClearIndex = false;

    /**
     * Gets a title for the job that can be used in listings
     *
     * @return string
     */
    public function getTitle()
    {
        return 'Rebuild Solr index';
    }
}
