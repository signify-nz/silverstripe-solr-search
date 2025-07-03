<?php

/**
 * class BaseIndex|Firesphere\SolrSearch\Indexes\BaseIndex is the base for indexing items
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in July 2025
 */

namespace Firesphere\SolrSearch\Indexes;

use Exception;
use Firesphere\SolrSearch\Factories\QueryComponentFactory;
use Firesphere\SolrSearch\Factories\SchemaFactory;
use Firesphere\SolrSearch\Helpers\SolrLogger;
use Firesphere\SolrSearch\Helpers\Synonyms;
use Firesphere\SolrSearch\Interfaces\ConfigStore;
use Firesphere\SolrSearch\Models\SearchSynonym;
use Firesphere\SolrSearch\Queries\BaseQuery;
use Firesphere\SolrSearch\Results\SearchResult;
use Firesphere\SolrSearch\Services\SolrCoreService;
use Firesphere\SolrSearch\States\SiteState;
use Firesphere\SolrSearch\Traits\GetterSetterTrait;
use LogicException;
use ReflectionClass;
use ReflectionException;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Deprecation;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBString;
use SilverStripe\ORM\ValidationException;
use SilverStripe\View\ArrayData;
use Solarium\Exception\HttpException;
use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Select\Result\Result;
use Solarium\Core\Client\Client;

/**
 * Base for creating a new Solr core.
 *
 * Base index settings and methods. Should be extended with at least a name for the index.
 * This is an abstract class that can not be instantiated on it's own
 *
 * @package Firesphere\Solr\Search
 */
abstract class BaseIndex
{
    use Extensible;
    use Configurable;
    use Injectable;
    use GetterSetterTrait;

    /**
     * Field types that can be added
     * Used in init to call build methods from configuration yml
     *
     * @array
     */
    private static $fieldTypes = [
        'FulltextFields',
        'SortFields',
        'FilterFields',
        'BoostedFields',
        'CopyFields',
        'DefaultField',
        'FacetFields',
        'StoredFields',
    ];
    /**
     * {@link SchemaFactory}
     *
     * @var SchemaFactory Schema factory for generating the schema
     */
    protected $schemaFactory;
    /**
     * {@link QueryComponentFactory}
     *
     * @var QueryComponentFactory Generator for all components
     */
    protected $queryFactory;
    /**
     * @var array The query terms as an array
     */
    protected $queryTerms = [];
    /**
     * @var Query Query that will hit the client
     */
    protected $clientQuery;
    /**
     * @var bool Signify if a retry should occur if nothing was found and there are suggestions to follow
     */
    private $retry = false;
    /**
     * @var bool Include dedicated spellcheck field to remove stemming
     */
    private static $include_dedicated_spellcheck_field = true;
    /**
     * @var Client Query client
     */
    protected $client;
    /**
     * @var array Facet fields
     */
    protected $facetFields = [];
    /**
     * @var array Fulltext fields
     */
    protected $fulltextFields = [];
    /**
     * @var array Filterable fields
     */
    protected $filterFields = [];
    /**
     * @var array Sortable fields
     */
    protected $sortFields = [];
    /**
     * @var string Default search field
     */
    protected $defaultField = '_text';
    /**
     * @var array Stored fields
     */
    protected $storedFields = [];
    /**
     * @var array Fields to copy to the default fields
     */
    protected $copyFields = [
        '_text' => [
            '*',
        ],
    ];
    /**
     * usedAllFields is used to determine if the addAllFields method has been called
     * This is to prevent a notice if there is no yml.
     *
     * @var bool
     */
    protected $usedAllFields = false;

    /**
     * BaseIndex constructor.
     */
    public function __construct()
    {
        // Set up the client
        $service = Injector::inst()->get(SolrCoreService::class);
        $config = $service->getClient()->getOptions();
        $config['endpoint'] = $this->getConfig($config['endpoint']);
        $this->client = $service->getClient();
        $this->client->setOptions($config);

        // Set up the schema service, only used in the generation of the schema
        /** @var SchemaFactory $schemaFactory */
        $schemaFactory = Injector::inst()->get(SchemaFactory::class, false);
        $schemaFactory->setIndex($this);
        $schemaFactory->setStore(Director::isDev());
        $this->schemaFactory = $schemaFactory;
        $this->queryFactory = Injector::inst()->get(QueryComponentFactory::class, false);

        $this->extend('onBeforeInit');
        $this->init();
        $this->extend('onAfterInit');
    }

    /**
     * Build a full config for all given endpoints
     * This is to add the current index to e.g. an index or select
     *
     * @param array $endpoints
     * @return array
     */
    public function getConfig($endpoints): array
    {
        foreach ($endpoints as $host => $endpoint) {
            $endpoints[$host]['core'] = $this->getIndexName();
        }

        return $endpoints;
    }

    /**
     * Name of this index.
     *
     * @return string
     */
    abstract public function getIndexName();

    /**
     * Required to initialise the fields.
     * It's loaded in to the non-static properties for backward compatibility with FTS
     * Also, it's a tad easier to use this way, loading the other way around would be very
     * memory intensive, as updating the config for each item is not efficient
     */
    public function init()
    {
        $config = self::config()->get($this->getIndexName());
        if (!$config) {
            Deprecation::notice('5', 'Please set an index name and use a config yml');
        }

        if (!empty($this->getClasses())) {
            if (!$this->usedAllFields) {
                Deprecation::notice('5', 'It is advised to use a config YML for most cases');
            }

            return;
        }

        $this->initFromConfig($config);
    }

    /**
     * Generate the config from yml if possible
     * @param array|null $config
     */
    protected function initFromConfig($config): void
    {
        if (!$config || !array_key_exists('Classes', $config)) {
            throw new LogicException('No classes or config to index found!');
        }

        $this->setClasses($config['Classes']);

        // For backward compatibility, copy the config to the protected values
        // Saves doubling up further down the line
        foreach (self::$fieldTypes as $type) {
            if (array_key_exists($type, $config)) {
                $method = 'set' . $type;
                $this->$method($config[$type]);
            }
        }
    }

    /**
     * Default returns a SearchResult. It can return an ArrayData if FTS Compat is enabled
     *
     * @param BaseQuery $query
     * @return SearchResult|ArrayData|mixed
     * @throws HTTPException
     * @throws ValidationException
     * @throws ReflectionException
     * @throws Exception
     */
    public function doSearch(BaseQuery $query)
    {
        SiteState::alterQuery($query);
        // Build the actual query parameters
        $this->clientQuery = $this->buildSolrQuery($query);
        // Set the sorting
        $this->clientQuery->addSorts($query->getSort());

        $this->extend('onBeforeSearch', $query, $this->clientQuery);

        try {
            $result = $this->client->select($this->clientQuery);
        } catch (Exception $error) {
            // @codeCoverageIgnoreStart
            $logger = new SolrLogger();
            $logger->saveSolrLog('Query');
            throw $error;
            // @codeCoverageIgnoreEnd
        }

        // Handle the after search first. This gets a raw search result
        $this->extend('onAfterSearch', $result);
        $searchResult = SearchResult::create($result, $query, $this);
        if ($this->doRetry($query, $result, $searchResult)) {
            // We need to override the spellchecking with the previous spellcheck
            // @todo refactor this to a cleaner way
            $collation = $result->getSpellcheck();
            $retryResults = $this->spellcheckRetry($query, $searchResult);
            $this->retry = false;
            return $retryResults->setCollatedSpellcheck($collation);
        }

        // And then handle the search results, which is a useable object for SilverStripe
        $this->extend('updateSearchResults', $searchResult);

        return $searchResult;
    }

    /**
     * From the given BaseQuery, generate a Solarium ClientQuery object
     *
     * @param BaseQuery $query
     * @return Query
     */
    public function buildSolrQuery(BaseQuery $query): Query
    {
        $clientQuery = $this->client->createSelect();
        $factory = $this->buildFactory($query, $clientQuery);

        $clientQuery = $factory->buildQuery();
        $this->queryTerms = $factory->getQueryArray();

        $queryData = implode(' ', $this->queryTerms);
        $clientQuery->setQuery($queryData);

        return $clientQuery;
    }

    /**
     * Build a factory to use in the SolrQuery building. {@link static::buildSolrQuery()}
     *
     * @param BaseQuery $query
     * @param Query $clientQuery
     * @return QueryComponentFactory|mixed
     */
    protected function buildFactory(BaseQuery $query, Query $clientQuery)
    {
        $factory = $this->queryFactory;

        $helper = $clientQuery->getHelper();

        $factory->setQuery($query);
        $factory->setClientQuery($clientQuery);
        $factory->setHelper($helper);
        $factory->setIndex($this);

        return $factory;
    }

    /**
     * Check if the query should be retried with spellchecking
     * Conditions are:
     * It is not already a retry with spellchecking
     * Spellchecking is enabled
     * Spellcheck following is enabled and nothing is found
     * There is a spellcheck output
     *
     * @param BaseQuery $query
     * @param Result $result
     * @param SearchResult $searchResult
     * @return bool
     */
    protected function doRetry(BaseQuery $query, Result $result, SearchResult $searchResult): bool
    {
        return !$this->retry &&
            $query->hasSpellcheck() &&
            ($query->shouldFollowSpellcheck() && $result->getNumFound() === 0) &&
            $searchResult->getCollatedSpellcheck();
    }

    /**
     * Retry the query with the first collated spellcheck found.
     *
     * @param BaseQuery $query
     * @param SearchResult $searchResult
     * @return SearchResult|mixed|ArrayData
     * @throws HTTPException
     * @throws ValidationException
     * @throws ReflectionException
     */
    protected function spellcheckRetry(BaseQuery $query, SearchResult $searchResult)
    {
        $terms = $query->getTerms();
        $spellChecked = $searchResult->getCollatedSpellcheck();
        // Remove the fuzzyness from the collated check
        $term = preg_replace('/~\d+/', '', $spellChecked);
        $terms[0]['text'] = $term;
        $query->setTerms($terms);
        $this->retry = true;

        return $this->doSearch($query);
    }

    /**
     * Get all fields that are required for indexing in a unique way
     *
     * @return array
     */
    public function getFieldsForIndexing(): array
    {
        $facets = [];
        foreach ($this->getFacetFields() as $field) {
            $facets[] = $field['Field'];
        }
        // Return values to make the key reset
        // Only return unique values
        // And make it all a single array
        $fields = array_values(
            array_unique(
                array_merge(
                    $this->getFulltextFields(),
                    $this->getSortFields(),
                    $facets,
                    $this->getFilterFields()
                )
            )
        );

        $this->extend('updateFieldsForIndexing', $fields);

        return $fields;
    }

    /**
     * Upload config for this index to the given store
     *
     * @param ConfigStore $store
     */
    public function uploadConfig(ConfigStore $store): void
    {
        // @todo use types/schema/elevate rendering
        // Upload the config files for this index
        // Create a default schema which we can manage later
        $schema = (string)$this->schemaFactory->generateSchema();
        $store->uploadString(
            $this->getIndexName(),
            'schema.xml',
            $schema
        );

        $this->getSynonyms($store);

        // Upload additional files
        foreach (glob($this->schemaFactory->getExtrasPath() . '/*') as $file) {
            if (is_file($file)) {
                $store->uploadFile($this->getIndexName(), $file);
            }
        }
    }

    /**
     * Add synonyms. Public to be extendable
     *
     * @param ConfigStore $store Store to use to write synonyms
     * @param bool $defaults Include UK to US synonyms
     * @return string
     */
    public function getSynonyms($store = null, $defaults = true)
    {
        $synonyms = Synonyms::getSynonymsAsString($defaults);
        /** @var DataList|SearchSynonym[] $syn */
        $syn = SearchSynonym::get();
        foreach ($syn as $synonym) {
            $synonyms .= $synonym->getCombinedSynonym();
        }

        // Upload synonyms
        if ($store) {
            $store->uploadString(
                $this->getIndexName(),
                'synonyms.txt',
                $synonyms
            );
        }

        return $synonyms;
    }

    /**
     * Get the final, generated terms
     *
     * @return array
     */
    public function getQueryTerms(): array
    {
        return $this->queryTerms;
    }

    /**
     * Get the QueryComponentFactory. {@link QueryComponentFactory}
     *
     * @return QueryComponentFactory
     */
    public function getQueryFactory(): QueryComponentFactory
    {
        return $this->queryFactory;
    }

    /**
     * Retrieve the Solarium client Query object for this index operation
     *
     * @return Query
     */
    public function getClientQuery(): Query
    {
        return $this->clientQuery;
    }

    /**
     * @return bool
     */
    public function isRetry(): bool
    {
        return $this->retry;
    }

    /**
     * Return the copy fields
     *
     * @return array
     */
    public function getCopyFields(): array
    {
        $this->setSpellcheckField();

        return $this->copyFields;
    }

    /**
     * Set the copy fields
     *
     * @param array $copyField
     * @return $this
     */
    public function setCopyFields($copyField): self
    {
        $this->copyFields = $copyField;

        $this->setSpellcheckField();

        return $this;
    }

    /**
     * Set the default copy field to use spellcheck with no stemming unless
     * set to false in config.
     *
     * @return void
     */
    public function setSpellcheckField()
    {
        $fields = $this->copyFields;

        $spellcheckNoStemming = $this->config()->get('include_dedicated_spellcheck_field');

        $spellcheckFieldExists = array_key_exists('_spellcheckText', $fields);

        if (!$spellcheckFieldExists && $spellcheckNoStemming == true) {
            $this->addCopyField('_spellcheckText', ['*', 'type' => 'textSpell']);
        }
    }

    /**
     * Return the default field for this index
     *
     * @return string
     */
    public function getDefaultField(): string
    {
        return $this->defaultField;
    }

    /**
     * Set the default field for this index
     *
     * @param string $defaultField
     * @return $this
     */
    public function setDefaultField($defaultField): self
    {
        $this->defaultField = $defaultField;

        return $this;
    }

    /**
     * Add a field to sort on
     *
     * @param $sortField
     * @return $this
     */
    public function addSortField($sortField): self
    {
        if (
            !in_array($sortField, $this->getFulltextFields(), true) &&
            !in_array($sortField, $this->getFilterFields(), true)
        ) {
            $this->addFulltextField($sortField);
            $this->sortFields[] = $sortField;
        }

        $this->setSortFields(array_unique($this->getSortFields()));

        return $this;
    }

    /**
     * Get the fulltext fields
     *
     * @return array
     */
    public function getFulltextFields(): array
    {
        return array_values(
            array_unique(
                $this->fulltextFields
            )
        );
    }

    /**
     * Set the fulltext fields
     *
     * @param array $fulltextFields
     * @return $this
     */
    public function setFulltextFields($fulltextFields): self
    {
        $this->fulltextFields = $fulltextFields;

        return $this;
    }

    /**
     * Get the filter fields
     *
     * @return array
     */
    public function getFilterFields(): array
    {
        return $this->filterFields;
    }

    /**
     * Set the filter fields
     *
     * @param array $filterFields
     * @return $this
     */
    public function setFilterFields($filterFields): self
    {
        $this->filterFields = $filterFields;

        return $this;
    }

    /**
     * Add a single Fulltext field
     *
     * @param string $fulltextField
     * @param null|string $forceType
     * @param array $options
     * @return $this
     */
    public function addFulltextField($fulltextField, $forceType = null, $options = []): self
    {
        if ($forceType) {
            Deprecation::notice('5.0', 'ForceType should be handled through casting');
        }

        $key = array_search($fulltextField, $this->getFilterFields(), true);

        if (!$key) {
            $this->fulltextFields[] = $fulltextField;
        }

        if (isset($options['boost'])) {
            $this->addBoostedField($fulltextField, [], $options['boost']);
        }

        if (isset($options['stored'])) {
            $this->storedFields[] = $fulltextField;
        }

        return $this;
    }

    /**
     * Get the sortable fields
     *
     * @return array
     */
    public function getSortFields(): array
    {
        return $this->sortFields;
    }

    /**
     * Set/override the sortable fields
     *
     * @param array $sortFields
     * @return $this
     */
    public function setSortFields($sortFields): self
    {
        $this->sortFields = $sortFields;

        return $this;
    }

    /**
     * Add all text-type fields to the given index
     *
     * @throws ReflectionException
     */
    public function addAllFulltextFields()
    {
        $this->addAllFieldsByType(DBString::class);
    }

    /**
     * Add all database-backed text fields as fulltext searchable fields.
     *
     * For every class included in the index, examines those classes and all parent looking for "DBText" database
     * fields (Varchar, Text, HTMLText, etc) and adds them all as fulltext searchable fields.
     *
     * Note, there is no check on boosting etc. That needs to be done manually.
     *
     * @param string $dbType
     * @throws ReflectionException
     */
    protected function addAllFieldsByType($dbType = DBString::class): void
    {
        $this->usedAllFields = true;
        $classes = $this->getClasses();
        foreach ($classes as $key => $class) {
            $fields = DataObject::getSchema()->databaseFields($class, true);

            $this->addFulltextFieldsForClass($fields, $dbType);
        }
    }

    /**
     * Add all fields of a given type to the index
     *
     * @param array $fields The fields on the DataObject
     * @param string $dbType Class type the reflection should extend
     * @throws ReflectionException
     */
    protected function addFulltextFieldsForClass(array $fields, $dbType = DBString::class): void
    {
        foreach ($fields as $field => $type) {
            $pos = strpos($type, '(');
            if ($pos !== false) {
                $type = substr($type, 0, $pos);
            }
            $conf = Config::inst()->get(Injector::class, $type);
            $ref = new ReflectionClass($conf['class']);
            if ($ref->isSubclassOf($dbType)) {
                $this->addFulltextField($field);
            }
        }
    }

    /**
     * Add all date-type fields to the given index
     *
     * @throws ReflectionException
     */
    public function addAllDateFields()
    {
        $this->addAllFieldsByType(DBDate::class);
    }

    /**
     * Add a facet field
     *
     * @param $field
     * @param array $options
     * @return $this
     */
    public function addFacetField($field, $options): self
    {
        $this->facetFields[$field] = $options;

        if (!in_array($options['Field'], $this->getFilterFields(), true)) {
            $this->addFilterField($options['Field']);
        }

        return $this;
    }

    /**
     * Add a filterable field
     *
     * @param $filterField
     * @return $this
     */
    public function addFilterField($filterField): self
    {
        $key = array_search($filterField, $this->getFulltextFields(), true);
        if ($key === false) {
            $this->filterFields[] = $filterField;
        }

        return $this;
    }

    /**
     * Add a copy field
     *
     * @param string $field Name of the copyfield
     * @param array $options Array of all fields that should be copied to this copyfield
     * @return $this
     */
    public function addCopyField($field, $options): self
    {
        $this->copyFields[$field] = $options;

        return $this;
    }

    /**
     * Add a stored/fulltext field
     *
     * @param string $field
     * @param null|string $forceType
     * @param array $extraOptions
     * @return SolrIndex
     */
    public function addStoredField($field, $forceType = null, $extraOptions = []): self
    {
        $options = array_merge($extraOptions, ['stored' => 'true']);
        $this->addFulltextField($field, $forceType, $options);

        return $this;
    }

    /**
     * Get the client
     *
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Set/override the client
     *
     * @param Client $client
     * @return $this
     */
    public function setClient($client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Get the stored field list
     *
     * @return array
     */
    public function getStoredFields(): array
    {
        return $this->storedFields;
    }

    /**
     * Set/override the stored field list
     *
     * @param array $storedFields
     * @return SolrIndex
     */
    public function setStoredFields(array $storedFields): self
    {
        $this->storedFields = $storedFields;

        return $this;
    }
}
