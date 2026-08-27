<?php

/**
 * Class FullSolrIndexJob|Firesphere\SolrSearch\Jobs\FullSolrIndexJob Index Solr cores
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in Aug 2024
 */

namespace Firesphere\SolrSearch\Jobs;

use Exception;
use Firesphere\SolrSearch\Helpers\SolrLogger;
use Firesphere\SolrSearch\Indexes\BaseIndex;
use Firesphere\SolrSearch\Models\SolrLog;
use Firesphere\SolrSearch\Services\SolrCoreService;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\Versioned\Versioned;
use Solarium\Exception\HttpException;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;

/**
 * FullSolrIndexJob is a queued job to index all existing indexes and their classes.
 *
 * It always runs on all indexes, to make sure all indexes are up to date.
 *
 * It will clear out any existing index data before running.
 *
 * @package Firesphere\Solr\Search
 */
class FullSolrIndexJob extends AbstractQueuedJob
{
    /**
     * The indexes that need to run.
     *
     * @var array
     */
    protected $indexes;

    /**
     * Current core being indexed
     *
     * @var BaseIndex
     */
    protected $index;

    /**
     * Default batch length.
     *
     * Kept small deliberately: if a batch fails or the job times out part way through,
     * the whole batch is retried from scratch (see {@link process()}). Building the
     * documents for a batch (relation traversal, permission checks per record) is the
     * expensive part, not the Solr write, so a small batch keeps a retry cheap rather
     * than needing to track progress within a batch.
     *
     * @var int
     */
    protected $batchLength = 50;

    /**
     * The logger to use
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Singleton of {@link SolrCoreService}
     *
     * @var SolrCoreService
     */
    protected $service;

    /**
     * Whether the job should clear the index before running.
     *
     * @var bool
     */
    protected $shouldClearIndex = true;

    /**
     * Gets a title for the job that can be used in listings
     *
     * @return string
     */
    public function getTitle()
    {
        return 'Clear and rebuild Solr index';
    }

    /**
     * {@inheritDoc}
     */
    public function setup()
    {
        $logsDeleted = SolrLog::truncateLogs();
        $this->addMessage('Cleared ' . $logsDeleted . ' logs from DB.');
        $this->indexes = (new SolrCoreService())->getValidIndexes();
        $this->currentStep = 0;
        $this->isComplete = false;
        $this->configureIndexableData();
        $this->addMessage('Calculated ' . $this->totalSteps . ' total batches to index.');
        $this->getLogger()->info('Calculated ' . $this->totalSteps . ' total batches to index.');
        $this->setService(Injector::inst()->get(SolrCoreService::class));
        if ($this->shouldClearIndex) {
            $this->clearIndexes();
        }
    }

    /**
     * Process this job
     *
     * @return self
     * @throws Exception
     * @throws HTTPException
     */
    public function process()
    {
        $indexableData = $this->indexableData;

        $data = array_pop($indexableData);
        if (!$this->index instanceof $data['index']) {
            $this->setIndex(Injector::inst()->get($data['index']));
        };
        $this->indexStateClass($data['index'], $data['class'], $data['group']);

        $this->indexableData = $indexableData;

        $this->currentStep++;

        if ($this->currentStep >= $this->totalSteps) {
            $this->isComplete = true;
        }

        return $this;
    }

    /**
     * Clear the given index if a full re-index is needed
     *
     * @throws Exception
     */
    public function clearIndexes()
    {
        $service = $this->getService();
        foreach ($this->indexes as $index) {
            $service->doManipulate(ArrayList::create([]), SolrCoreService::DELETE_TYPE_ALL, Injector::inst()->get($index));
        }
    }

    /**
     * Index a group of a class for a specific state and index
     *
     * @param string $index Name of index
     * @param string $group Group to index
     * @param string $class Class to index
     * @throws Exception
     * @return void
     */
    private function indexStateClass(string $index, string $class, string $group): void
    {
        $subsiteFilter = null;
        if (ClassInfo::exists(Subsite::class)) {
            $subsiteFilter = Subsite::$disable_subsite_filter;
            Subsite::$disable_subsite_filter = true;
        }

        // Generate filtered list of local records
        $baseClass = DataObject::getSchema()->baseDataClass($class);
        /** @var DataList|DataObject[] $items */
        $items = DataObject::get($baseClass);
        if (!empty($classes = Config::inst()->get($index, 'exclude_classes'))) {
            $items = $items->exclude(['ClassName' => $classes]);
        }

        // Index live content only. Queued jobs run without a request, so
        // Versioned defaults to the draft stage; without forcing live here the
        // job indexes draft-only and unpublished pages. Those records inflate
        // the result count on the live site (the front-end rehydrates matches
        // from the live stage and silently drops anything not present there).
        // Use set_stage(), not set_reading_mode(Versioned::LIVE): the latter
        // sets the malformed mode 'Live' (not 'Stage.Live'), which DataObject::get()
        // does not treat as a live-stage filter, so drafts would still be indexed.
        $readingMode = Versioned::get_reading_mode();
        Versioned::set_stage(Versioned::LIVE);
        try {
            $items = $this->getIndexableRecords($index, $class)
                ->sort('ID ASC')
                ->limit($this->getBatchLength(), ($group * $this->getBatchLength()));
            if ($items->count()) {
                $this->updateIndex($items);
            }
        } finally {
            Versioned::set_reading_mode($readingMode);
        }

        if (!is_null($subsiteFilter)) {
            Subsite::$disable_subsite_filter = $subsiteFilter;
        }
    }

    /**
     * Execute the update on the client
     *
     * @param SS_List $items Items to index
     * @throws Exception
     * @return void
     */
    protected function updateIndex($items): void
    {
        $index = $this->getIndex();
        $service = $this->getService();
        $service->setDebug(true);
        try {
            // Use doManipulate() rather than building the update directly, so the
            // batch is committed (and the searcher reopened) the same way the
            // incremental index path does. Without this, added documents are
            // written to Solr's transaction log but never become searchable.
            $service->doManipulate($items, SolrCoreService::UPDATE_TYPE, $index);
        } catch (Exception $error) {
            $this->logException($index->getIndexName(), $error);
        }
    }

    /**
     * Set up array of indexable data and set total number of steps.
     *
     * @return void
     */
    protected function configureIndexableData(): void
    {
        $steps = 0;
        $indexableData = [];
        $indexes = $this->indexes;
        foreach ($indexes as $index) {
            $indexInstance = Injector::inst()->get($index);
            $batchLength = $this->getBatchLength();
            foreach ($indexInstance->getClasses() as $class) {
                $batchesCount = $this->getBatchesCount($index, $class, $batchLength);
                $steps += $batchesCount;
                for ($n = 0; $n < $batchesCount; $n++) {
                    $indexableData[] = ['index' => $index, 'class' => $class, 'group' => $n];
                }
            }
        }
        // Deliberately not a declared property: AbstractQueuedJob's __get()/__set() only
        // route *undeclared* properties through $jobData, which is what actually gets
        // persisted to the job descriptor and restored after a restart.
        $this->indexableData = $indexableData;
        $this->totalSteps = $steps;
    }

    /**
     * Build the filtered list of records to index for a class.
     *
     * Records are read in the caller's current Versioned reading mode, so
     * callers must force {@link Versioned::LIVE} when indexing for the live
     * site (see {@link indexStateClass()} and {@link getBatchesCount()}).
     *
     * @param  string $index
     * @param  string $class
     * @return DataList|DataObject[]
     */
    private function getIndexableRecords(string $index, string $class): DataList
    {
        // Generate filtered list of local records
        $baseClass = DataObject::getSchema()->baseDataClass($class);
        /** @var DataList|DataObject[] $items */
        $items = DataObject::get($baseClass);
        if (!empty($classes = Config::inst()->get($index, 'exclude_classes'))) {
            $items = $items->exclude(['ClassName' => $classes]);
        }

        return $items;
    }

    /**
     * Calculate the number of batches that should be indexed for given class / index.
     *
     * @param  string $index
     * @param  string $class
     * @param  int $batchLength
     * @return int
     */
    protected function getBatchesCount(string $index, string $class, int $batchLength)
    {
        // Count live records only, to stay consistent with indexStateClass().
        $readingMode = Versioned::get_reading_mode();
        Versioned::set_stage(Versioned::LIVE);
        try {
            $batches = $this->getIndexableRecords($index, $class)->count() / $batchLength;
        } finally {
            Versioned::set_reading_mode($readingMode);
        }

        $batches = (int) ceil($batches);
        $this->addMessage('Adding ' . $batches . ' batches of ' . $class . ' to index.');
        $this->getLogger()->info('Adding ' . $batches . ' batches of ' . $class . ' to index.');
        return $batches;
    }

    /**
     * Log an exception if it happens. Most are catched, these logs are for the developers
     * to identify problems and fix them.
     *
     * @codeCoverageIgnore This is actually tested through reflection
     * @param string $index Index that is currently running
     * @param Exception $exception Exception that's been thrown
     * @throws HTTPException
     * @throws ValidationException
     * @return void
     */
    private function logException($index, Exception $exception): void
    {
        $msg = sprintf(
            'Error indexing core %s,' . PHP_EOL .
                'Please log in to the CMS to find out more about Indexing errors' . PHP_EOL,
            $index
        );
        $this->getLogger()->error($exception->getMessage());
        $this->getLogger()->error($msg);

        SolrLogger::logMessage('ERROR', $msg);
    }

    /**
     * Get the indexes
     *
     * @return array
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Set the indexes if needed
     *
     * @param array $indexes
     */
    public function setIndexes($indexes)
    {
        $this->indexes = $indexes;
    }

    /**
     * Get the length of a single batch
     *
     * @return int
     */
    public function getBatchLength(): int
    {
        return $this->batchLength;
    }

    /**
     * Set the length of a single batch
     *
     * @param int $batchLength
     * @return void
     */
    public function setBatchLength(int $batchLength): void
    {
        $this->batchLength = $batchLength;
    }

    /**
     * Get an instance of SolrCoreService
     *
     * @return SolrCoreService
     */
    public function getService(): SolrCoreService
    {
        return $this->service;
    }

    /**
     * Set an instance of SolrCoreService
     *
     * @param SolrCoreService $service
     * @return void
     */
    public function setService($service): void
    {
        $this->service = $service;
    }

    /**
     * Get the logger
     *
     * @return LoggerInterface
     */
    public function getLogger()
    {
        if (!$this->logger) {
            $this->logger = Injector::inst()->get(LoggerInterface::class);
        }

        return $this->logger;
    }

    /**
     * Set the logger if needed
     *
     * @param LoggerInterface $logger
     * @return void
     */
    public function setLogger($logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Get an instance of the current index class.
     *
     * @return BaseIndex
     */
    public function getIndex(): BaseIndex
    {
        return $this->index;
    }

    /**
     * Set an instance of the current index class
     *
     * @param BaseIndex $index
     * @return void
     */
    public function setIndex(BaseIndex $index): void
    {
        $this->index = $index;
    }
}
