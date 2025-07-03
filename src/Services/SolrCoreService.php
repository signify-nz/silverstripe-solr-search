<?php

/**
 * class SolrCoreService|Firesphere\SolrSearch\Services\SolrCoreService Base service for communicating with the core
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in July 2025
 */

namespace Firesphere\SolrSearch\Services;

use Exception;
use Firesphere\SolrSearch\Factories\DocumentFactory;
use Firesphere\SolrSearch\Helpers\FieldResolver;
use Firesphere\SolrSearch\Helpers\SolrLogger;
use Firesphere\SolrSearch\Indexes\BaseIndex;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use LogicException;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\SS_List;
use Solarium\Client;
use Solarium\Core\Client\Adapter\Psr18Adapter;
use Solarium\Core\Client\Client as CoreClient;
use Solarium\QueryType\Update\Query\Query;
use Solarium\QueryType\Update\Result;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Solarium\QueryType\Server\CoreAdmin\Query\Query as CoreAdminQuery;

/**
 * Class SolrCoreService provides the base connection to Solr.
 *
 * Default service to connect to Solr and handle all base requirements to support Solr.
 * Default constants are available to support any set up.
 *
 * @package Firesphere\Solr\Search
 */
class SolrCoreService
{
    use Injectable;
    use Configurable;

    /**
     * Unique ID in Solr
     */
    const ID_FIELD = 'id';
    /**
     * SilverStripe ID of the object
     */
    const CLASS_ID_FIELD = 'ObjectID';
    /**
     * Name of the field that can be used for queries
     */
    const CLASSNAME = 'ClassName';
    /**
     * Solr update types
     */
    const DELETE_TYPE_ALL = 'deleteall';
    /**
     * string
     */
    const DELETE_TYPE = 'delete';
    /**
     * string
     */
    const UPDATE_TYPE = 'update';
    /**
     * string
     */
    const CREATE_TYPE = 'create';

    /**
     * @var array Base indexes that exist
     */
    protected $baseIndexes = [];
    /**
     * @var array Valid indexes out of the base indexes
     */
    protected $validIndexes = [];
    /**
     * @var array Available config versions
     */
    protected static $solr_versions = [
        "9.99999999.0",
        "7.99999999.0",
        "5.99999999.0",
        "4.99999999.0",
    ];

    /**
     * @var array|null Option for use in Solarium\QueryType\Update\Query\Query::addDocuments() function
     */
    private static $add_docs_overwrite;

    /**
     * @var array|null Option for use in Solarium\QueryType\Update\Query\Query::addDocuments() function
     */
    private static $add_docs_commitWithin;

    /**
     * @var CoreAdminQuery A core admin object
     */
    protected $admin;
    /**
     * @var Client The current client
     */
    protected $client;
    /**
     * Add debugging information
     *
     * @var bool
     */
    protected $debug = false;

    /**
     * SolrCoreService constructor.
     *
     * @throws ReflectionException
     */
    public function __construct()
    {
        $config = static::config()->get('config');
        $httpClient = HTTPClientDiscovery::find();
        $requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = Psr17FactoryDiscovery::findStreamFactory();
        $eventDispatcher = new EventDispatcher();
        $adapter = new Psr18Adapter($httpClient, $requestFactory, $streamFactory);
        $this->client = new Client($adapter, $eventDispatcher, $config);
        $this->admin = $this->client->createCoreAdmin();
        $this->baseIndexes = ClassInfo::subclassesFor(BaseIndex::class);
        $this->filterIndexes();
    }

    /**
     * Filter enabled indexes down to valid indexes that can be instantiated
     * or are allowed from config
     *
     * @throws ReflectionException
     */
    protected function filterIndexes(): void
    {
        $enabledIndexes = static::config()->get('indexes');
        $enabledIndexes = is_array($enabledIndexes) ? $enabledIndexes : $this->baseIndexes;
        foreach ($this->baseIndexes as $subindex) {
            // If the config of indexes is set, and the requested index isn't in it, skip addition
            // Or, the index simply doesn't exist, also a valid option
            if (
                !in_array($subindex, $enabledIndexes, true) ||
                !$this->checkReflection($subindex)
            ) {
                continue;
            }
            $this->validIndexes[] = $subindex;
        }
    }

    /**
     * Check if the class is instantiable
     *
     * @param $subindex
     * @return bool
     * @throws ReflectionException
     */
    protected function checkReflection($subindex): bool
    {
        $reflectionClass = new ReflectionClass($subindex);

        return $reflectionClass->isInstantiable();
    }

    /**
     * Update items in the list to Solr
     *
     * @param SS_List|DataObject $items
     * @param string $type
     * @param null|string $index
     * @return bool|Result
     * @throws ReflectionException
     * @throws Exception
     */
    public function updateItems($items, $type, $index = null)
    {
        $indexes = $this->getValidIndexes($index);

        $result = false;
        $items = ($items instanceof DataObject) ? ArrayList::create([$items]) : $items;
        $items = ($items instanceof SS_List) ? $items : ArrayList::create($items);

        $hierarchy = FieldResolver::getHierarchy($items->first()->ClassName);

        foreach ($indexes as $indexString) {
            /** @var BaseIndex $index */
            $index = Injector::inst()->get($indexString);
            $classes = $index->getClasses();
            $inArray = array_intersect($classes, $hierarchy);
            // No point in sending a delete|update|create for something that's not in the index
            if (!count($inArray)) {
                continue;
            }

            $result = $this->doManipulate($items, $type, $index);
        }

        return $result;
    }

    /**
     * Get valid indexes for the project
     *
     * @param null|string $index
     * @return array
     */
    public function getValidIndexes($index = null): array
    {
        if ($index && !in_array($index, $this->validIndexes, true)) {
            throw new LogicException('Incorrect index ' . $index);
        }

        if ($index) {
            return [$index];
        }

        // return the array values, to reset the keys
        return array_values($this->validIndexes);
    }

    /**
     * Execute the manipulation of solr documents
     *
     * @param SS_List $items
     * @param $type
     * @param BaseIndex $index
     * @return Result
     * @throws Exception
     */
    public function doManipulate($items, $type, BaseIndex $index): Result
    {
        $client = $index->getClient();

        $update = $this->getUpdate($items, $type, $index, $client);

        // commit immediately when in dev mode

        return $client->update($update);
    }

    /**
     * get the update object ready
     *
     * @param SS_List $items
     * @param string $type
     * @param BaseIndex $index
     * @param CoreClient $client
     * @return mixed
     * @throws Exception
     */
    protected function getUpdate($items, $type, BaseIndex $index, CoreClient $client)
    {
        // get an update query instance
        $update = $client->createUpdate();

        switch ($type) {
            case static::DELETE_TYPE:
                // By pushing to a single array, we have less memory usage and no duplicates
                // This is faster, and more efficient, because we only do one DB query
                $delete = $items->map('ID', 'ClassName')->toArray();
                array_walk($delete, static function (&$item, $key) {
                    $item = sprintf('%s-%s', $item, $key);
                });
                $update->addDeleteByIds(array_values($delete));
                // Remove the deletion array from memory
                break;
            case static::DELETE_TYPE_ALL:
                $update->addDeleteQuery('*:*');
                break;
            case static::UPDATE_TYPE:
            case static::CREATE_TYPE:
                $this->updateIndex($index, $items, $update);
                break;
        }
        $update->addCommit();

        return $update;
    }

    /**
     * Create the documents and add to the update
     *
     * @param BaseIndex $index
     * @param SS_List $items
     * @param Query $update
     * @throws Exception
     */
    public function updateIndex($index, $items, $update): void
    {
        $fields = $index->getFieldsForIndexing();
        $factory = $this->getFactory($items);
        $docs = $factory->buildItems($fields, $index, $update);
        if (count($docs)) {
            $update->addDocuments(
                $docs,
                $this->config()->get('add_docs_overwrite'),
                $this->config()->get('add_docs_commitWithin')
            );
        }
    }

    /**
     * Get the document factory prepared
     *
     * @param SS_List $items
     * @return DocumentFactory
     */
    protected function getFactory($items): DocumentFactory
    {
        $factory = Injector::inst()->get(DocumentFactory::class);
        $factory->setItems($items);
        $factory->setClass($items->first()->ClassName);
        $factory->setDebug($this->isDebug());

        return $factory;
    }

    /**
     * Check the Solr version to use
     * In version compare, we have the following results:
     *       1 means "result version is higher"
     *       0 means "result version is equal"
     *      -1 means "result version is lower"
     * We want to use the version "equal to or less than",
     * because there are non-backwards compatible changes in Solr version 9.
     * We loop through the versions available from high to low.
     *
     * If no valid version is found, throw an error
     *
     * @param HandlerStack|null $handler Used for testing the solr version
     * @throws LogicException
     * @return int
     */
    public function getSolrVersion($handler = null): int
    {
        $config = self::config()->get('config');
        $firstEndpoint = array_shift($config['endpoint']);
        $clientConfig = [
            'base_uri' => 'http://' . $firstEndpoint['host'] . ':' . $firstEndpoint['port'],
        ];

        if ($handler) {
            $clientConfig['handler'] = $handler;
        }

        $clientOptions = $this->getSolrAuthentication($firstEndpoint);

        $client = new GuzzleClient($clientConfig);

        $result = $client->get('solr/admin/info/system?wt=json', $clientOptions);
        $result = json_decode($result->getBody(), 1);

        $lastKey = array_key_last(static::$solr_versions);
        $lastVersion = static::$solr_versions[$lastKey];

        foreach (static::$solr_versions as $version) {
            $serverVersion = $result['lucene']['solr-spec-version'];
            $compare = version_compare($version, $serverVersion);
            if (
                (int)$version === (int)$serverVersion ||
                $compare === 0 ||
                $compare === -1
            ) {
                list($v) = explode('.', $version);
                return (int)$v;
            }
            if ($version === $lastVersion) {
                return 4;
            }
        }

        throw new LogicException('No valid version of Solr found!', 255);
    }

    /**
     * This will add the authentication headers to the request.
     * It's intended to become a helper in the end.
     *
     * @param array $firstEndpoint
     * @return array|array[]
     */
    private function getSolrAuthentication($firstEndpoint): array
    {
        $clientOptions = [];

        if (isset($firstEndpoint['username']) && isset($firstEndpoint['password'])) {
            $clientOptions = [
                'auth' => [
                    $firstEndpoint['username'],
                    $firstEndpoint['password']
                ]
            ];
        }

        return $clientOptions;
    }

    /**
     * Create a new core
     *
     * @param $core string - The name of the core
     * @param ConfigStore $configStore
     * @return bool
     * @throws Exception
     * @throws HTTPException
     */
    public function coreCreate($core, $configStore): bool
    {
        $action = $this->admin->createCreate();

        $action->setCore($core);
        $path = SolrCoreService::config()->get('solr_path') ?? $configStore->instanceDir($core);
        $action->setInstanceDir($path);
        $this->admin->setAction($action);
        try {
            $response = $this->client->coreAdmin($this->admin);

            return $response->getWasSuccessful();
            // @codeCoverageIgnoreStart
        } catch (Exception $error) {
            $solrLogger = new SolrLogger();
            $solrLogger->saveSolrLog('Config');

            throw new Exception($error);
        }
        // @codeCoverageIgnoreEnd
    }


    /**
     * Reload the given core
     *
     * @param $core
     * @return StatusResult|null
     */
    public function coreReload($core)
    {
        $reload = $this->admin->createReload();
        $reload->setCore($core);

        $this->admin->setAction($reload);

        $response = $this->client->coreAdmin($this->admin);

        return $response->getStatusResult();
    }

    /**
     * Get the core status
     *
     * @param string $core
     * @return StatusResult|null
     */
    public function coreStatus($core)
    {
        $status = $this->admin->createStatus();
        $status->setCore($core);

        $this->admin->setAction($status);
        $response = $this->client->coreAdmin($this->admin);

        return $response->getStatusResult();
    }

    /**
     * Remove a core from Solr
     *
     * @param string $core core name
     * @return StatusResult|null A result is successful
     */
    public function coreUnload($core)
    {
        $unload = $this->admin->createUnload();
        $unload->setCore($core);

        $this->admin->setAction($unload);
        $response = $this->client->coreAdmin($this->admin);

        return $response->getStatusResult();
    }

    /**
     * Get the admin
     *
     * @return CoreAdminQuery
     */
    public function getAdmin(): CoreAdminQuery
    {
        return $this->admin;
    }

    /**
     * Set the admin
     *
     * @param CoreAdminQuery $admin
     * @return self
     */
    public function setAdmin($admin): self
    {
        $this->admin = $admin;

        return $this;
    }

    /**
     * Get the client
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Set the client
     *
     * @param Client $client
     * @return self
     */
    public function setClient($client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Check if we are in debug mode
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Set the debug mode
     *
     * @param bool $debug
     * @return self
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;

        return $this;
    }


    /**
     * Is the given class a valid class to index
     * Does not discriminate against the indexes. All indexes are worth the same
     *
     * @param string $class
     * @return bool
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function isValidClass($class): bool
    {
        $classes = $this->getValidClasses();

        return in_array($class, $classes, true);
    }

    /**
     * Get all classes from all indexes and return them.
     * Used to get all classes that are to be indexed on change
     * Note, only base classes are in this object. A publish recursive is required
     * when any change from a relation is published.
     *
     * @return array
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function getValidClasses(): array
    {
        /** @var CacheInterface $cache */
        $cache = Injector::inst()->get(CacheInterface::class . '.SolrCache');

        if ($cache->has('ValidClasses')) {
            return $cache->get('ValidClasses');
        }

        $indexes = $this->getValidIndexes();
        $classes = [];
        foreach ($indexes as $index) {
            $classes = $this->getClassesInHierarchy($index, $classes);
            if (!empty($exclude = singleton($index)->config()->get('exclude_classes'))) {
                $classes = array_diff($classes, $exclude);
            }
        }

        $cache->set('ValidClasses', array_unique($classes));

        return array_unique($classes);
    }

    /**
     * Get the classes in hierarchy to see if it's valid
     *
     * @param string $index Index to check classes for
     * @param array $classes Classes to get hierarchy for
     * @return array
     * @throws ReflectionException
     */
    protected function getClassesInHierarchy($index, array $classes): array
    {
        $indexClasses = singleton($index)->getClasses();
        foreach ($indexClasses as $class) {
            $classes = array_merge($classes, FieldResolver::getHierarchy($class));
        }

        return $classes;
    }
}
