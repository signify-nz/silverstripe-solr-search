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
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\SS_List;
use SilverStripe\Subsites\Model\Subsite;
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
     * Class info of content to be indexed.
     * Fits the following structure:
     * [
     *      'index' => string,
     *      'class' => string,
     *      'group' => int
     * ]
     *
     * @var array
     */
    protected $indexableData = [];

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
     * @var int
     */
    protected $batchLength = 500;

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
        if($this->shouldClearIndex) {
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
        $this->currentStep++;

        $data = array_pop($this->indexableData);
        if (!$this->index instanceof $data['index']) {
            $this->setIndex(Injector::inst()->get($data['index']));
        };
        $this->indexStateClass($data['index'], $data['class'], $data['group']);

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
        foreach($this->indexes as $index) {
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
        $items = $items->sort('ID ASC')
            ->limit($this->getBatchLength(), ($group * $this->getBatchLength()));
        if ($items->count()) {
            $this->updateIndex($items);
        }

        if(!is_null($subsiteFilter)) {
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
        $client = $index->getClient();
        $update = $client->createUpdate();
        $service = $this->getService();
        $service->setDebug(true);
        try {
            $service->updateIndex($index, $items, $update);
            $client->update($update);
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
        $indexes = $this->indexes;
        foreach ($indexes as $index) {
            $indexInstance = Injector::inst()->get($index);
            $batchLength = $this->getBatchLength();
            foreach ($indexInstance->getClasses() as $class) {
                $batchesCount = $this->getBatchesCount($index, $class, $batchLength);
                $steps += $batchesCount;
                for ($n = 0; $n < $batchesCount; $n++) {
                    $this->indexableData[] = ['index' => $index, 'class' => $class, 'group' => $n];
                }
            }
        }
        $this->totalSteps = $steps;
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
        // Generate filtered list of local records
        $baseClass = DataObject::getSchema()->baseDataClass($class);
        /** @var DataList|DataObject[] $items */
        $items = DataObject::get($baseClass);
        if (!empty($classes = Config::inst()->get($index, 'exclude_classes'))) {
            $items = $items->exclude(['ClassName' => $classes]);
        }
        $batches = $items->count() / $batchLength;
        $this->addMessage('Adding ' . ceil($batches) . ' batches of ' . $class . ' to index.');
        $this->getLogger()->info('Adding ' . ceil($batches) . ' batches of ' . $class . ' to index.');
        return ceil($batches);
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
     * Get array of data to index
     *
     * @return array
     */
    public function getIndexableData(): array
    {
        return $this->indexableData;
    }

    /**
     * Set array of data to index
     *
     * @param array $indexableData
     * @return FullSolrIndexJob
     */
    public function setIndexableData($indexableData)
    {
        $this->indexableData = $indexableData;

        return $this;
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
