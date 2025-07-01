<?php

/**
 * class QueryComponentFactory|Firesphere\SolrSearch\Factories\QueryComponentFactory Build a Query component
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in July 2025
 */

namespace Firesphere\SolrSearch\Factories;

use Firesphere\SolrSearch\Indexes\BaseIndex;
use Firesphere\SolrSearch\Queries\BaseQuery;
use Firesphere\SolrSearch\Services\SolrCoreService;
use Minimalcode\Search\Criteria;
use SilverStripe\Security\Security;
use Solarium\Core\Query\Helper;
use Solarium\QueryType\Select\Query\Query;

/**
 * Class QueryComponentFactory
 *
 * Build a query component for each available build part
 *
 * @package Firesphere\Solr\Search
 */
class QueryComponentFactory
{
    /**
     * Default fields that should always be added
     *
     * @var array
     */
    const DEFAULT_FIELDS = [
        SolrCoreService::ID_FIELD,
        SolrCoreService::CLASS_ID_FIELD,
        SolrCoreService::CLASSNAME,
    ];

    /**
     * @var array Build methods to run
     */
    protected static $builds = [
        'Terms',
        'ViewFilter',
        'ClassFilter',
        'Filters',
        'Excludes',
        'QueryFacets',
        'AndFacetFilterQuery',
        'OrFacetFilterQuery',
        'Spellcheck',
    ];
    /**
     * @var BaseQuery BaseQuery that needs to be executed
     */
    protected $query;
    /**
     * @var Helper Helper to escape the query terms properly
     */
    protected $helper;
    /**
     * @var array Resulting query parts as an array
     */
    protected $queryArray = [];
    /**
     * @var BaseIndex Index to query
     */
    protected $index;
    /**
     * Terms that are going to be boosted
     *
     * @var array
     */
    protected $boostTerms = [];
    /**
     * @var Query Solarium query
     */
    protected $clientQuery;

    /**
     * Build the full query
     *
     * @return Query
     */
    public function buildQuery(): Query
    {
        foreach (static::$builds as $build) {
            $method = sprintf('build%s', $build);
            $this->$method();
        }
        // Set the start
        $this->clientQuery->setStart($this->query->getStart());
        // Double the rows in case something has been deleted, but not from Solr
        $this->clientQuery->setRows($this->query->getRows() * 2);
        // Add highlighting before adding boosting
        $this->clientQuery->getHighlighting()->setFields($this->query->getHighlight());
        // Add boosting
        $this->buildBoosts();

        // Filter out the fields we want to see if they're set
        $fields = $this->query->getFields();
        if (count($fields)) {
            // We _ALWAYS_ need the ClassName for getting the DataObjects back
            $fields = array_merge(static::DEFAULT_FIELDS, $fields);
            $this->clientQuery->setFields($fields);
        }

        return $this->clientQuery;
    }

    /**
     * Get the base query
     *
     * @return BaseQuery
     */
    public function getQuery(): BaseQuery
    {
        return $this->query;
    }

    /**
     * Set the base query
     *
     * @param BaseQuery $query
     * @return self
     */
    public function setQuery(BaseQuery $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Get the array of terms used to query Solr
     *
     * @return array
     */
    public function getQueryArray(): array
    {
        return array_merge($this->queryArray, $this->boostTerms);
    }

    /**
     * Set the array of queries that are sent to Solr
     *
     * @param array $queryArray
     * @return self
     */
    public function setQueryArray(array $queryArray): self
    {
        $this->queryArray = $queryArray;

        return $this;
    }

    /**
     * Get the client Query components
     *
     * @return Query
     */
    public function getClientQuery(): Query
    {
        return $this->clientQuery;
    }

    /**
     * Set a custom Client Query object
     *
     * @param Query $clientQuery
     * @return self
     */
    public function setClientQuery(Query $clientQuery): self
    {
        $this->clientQuery = $clientQuery;

        return $this;
    }

    /**
     * Get the query helper
     *
     * @return Helper
     */
    public function getHelper(): Helper
    {
        return $this->helper;
    }

    /**
     * Set the Helper
     *
     * @param Helper $helper
     * @return self
     */
    public function setHelper(Helper $helper): self
    {
        $this->helper = $helper;

        return $this;
    }

    /**
     * Get the BaseIndex
     *
     * @return BaseIndex
     */
    public function getIndex(): BaseIndex
    {
        return $this->index;
    }

    /**
     * Set a BaseIndex
     *
     * @param BaseIndex $index
     * @return self
     */
    public function setIndex(BaseIndex $index): self
    {
        $this->index = $index;

        return $this;
    }

    /**
     * Build the terms and boost terms
     *
     * @return void
     */
    protected function buildTerms(): void
    {
        $terms = $this->query->getTerms();
        $boostTerms = $this->getBoostTerms();

        foreach ($terms as $search) {
            $term = $this->getBuildTerm($search);
            $postfix = $this->isFuzzy($search);
            // We can add the same term multiple times with different boosts
            // Not ideal, but it might happen, so let's add the term itself only once
            if (!in_array($term, $this->queryArray, true)) {
                $this->queryArray[] = $term . $postfix;
            }
            // If boosting is set, add the fields to boost
            if ($search['boost'] > 1) {
                $boostTerms = $this->buildQueryBoost($search, $term, $boostTerms);
            }
        }
        // Clean up the boost terms, remove doubles
        $this->setBoostTerms(array_values(array_unique($boostTerms)));
    }

    /**
     * Get the escaped search string, or, if empty, a global search
     *
     * @param array $search
     * @return string
     */
    protected function getBuildTerm($search)
    {
        $term = $search['text'];
        $term = $this->escapeSearch($term);
        if ($term === '') {
            $term = '*:*';
        }

        return $term;
    }

    /**
     * Escape the search query
     *
     * @param string $searchTerm
     * @return string
     */
    public function escapeSearch($searchTerm): string
    {
        $term = [];
        // Escape special characters where needed. Except for quoted parts, those should be phrased
        preg_match_all('/"[^"]*"|\S+/', $searchTerm, $parts);
        foreach ($parts[0] as $part) {
            $escaped = $this->helper->escapeTerm($part);
            // As we split the parts, everything with two quotes is a phrase
            // We need however, to strip out double quoting
            if (substr_count($part, '"') === 2) {
                // Strip all double quotes out for the phrase.
                // @todo make this less clunky
                // @todo add useful tests for this
                $part = str_replace('"', '', $part);
                $escaped = $this->helper->escapePhrase($part);
            }
            $term[] = $escaped;
        }

        return implode(' ', $term);
    }

    /**
     * If the search is fuzzy, add fuzzyness
     *
     * @param $search
     * @return string
     */
    protected function isFuzzy($search): string
    {
        // When doing fuzzy search, postfix, otherwise, don't
        if ($search['fuzzy']) {
            return '~' . (is_numeric($search['fuzzy']) ? $search['fuzzy'] : '');
        }

        return '';
    }

    /**
     * Add spellcheck elements
     */
    protected function buildSpellcheck(): void
    {
        // Assuming the first term is the term entered
        $queryString = implode(' ', $this->queryArray);
        // Arbitrarily limit to 5 if the config isn't set
        $count = BaseIndex::config()->get('spellcheckCount') ?: 5;
        $spellcheck = $this->clientQuery->getSpellcheck();
        $spellcheck->setQuery($queryString);
        $spellcheck->setCount($count);
        $spellcheck->setBuild(true);
        $spellcheck->setCollate(true);
        $spellcheck->setExtendedResults(true);
        $spellcheck->setCollateExtendedResults(true);
    }

    /**
     * Get the boosted terms
     *
     * @return array
     */
    public function getBoostTerms(): array
    {
        return $this->boostTerms;
    }

    /**
     * Set the boosted terms manually
     *
     * @param array $boostTerms
     * @return QueryComponentFactory
     */
    public function setBoostTerms(array $boostTerms): self
    {
        $this->boostTerms = $boostTerms;

        return $this;
    }

    /**
     * Build the boosted field setup through Criteria
     *
     * Add the index-time boosting to the query
     */
    protected function buildBoosts(): void
    {
        $boostedFields = $this->query->getBoostedFields();
        $queries = $this->getQueryArray();
        foreach ($boostedFields as $field => $boost) {
            $terms = [];
            foreach ($queries as $term) {
                $terms[] = $term;
            }
            if (count($terms)) {
                $booster = Criteria::where(str_replace('.', '_', $field))
                    ->in($terms)
                    ->boost($boost);
                $this->queryArray[] = $booster->getQuery();
            }
        }
    }

    /**
     * Set boosting at Query time
     *
     * @param array $search
     * @param string $term
     * @param array $boostTerms
     * @return array
     */
    protected function buildQueryBoost($search, string $term, array &$boostTerms): array
    {
        foreach ($search['fields'] as $boostField) {
            $boostField = str_replace('.', '_', $boostField);
            $criteria = Criteria::where($boostField)
                ->is($term)
                ->boost($search['boost']);
            $boostTerms[] = $criteria->getQuery();
        }

        return $boostTerms;
    }

    /**
     * Add facets from the index, to make sure Solr returns
     * the expected facets and their respective count on the
     * correct fields
     */
    protected function buildQueryFacets(): void
    {
        $facets = $this->clientQuery->getFacetSet();
        // Facets should be set from the index configuration
        foreach ($this->index->getFacetFields() as $config) {
            $shortClass = getShortFieldName($config['BaseClass']);
            $underscoredField = str_replace('.', '_', $config['Field']);
            $field = sprintf('%s_%s', $shortClass, $underscoredField);
            /** @var Field $facet */
            $facet = $facets->createFacetField('facet-' . $config['Title']);
            $facet->setField($field);
        }
        // Count however, comes from the query
        $facets->setMinCount($this->query->getFacetsMinCount());
    }

    /**
     * Add AND facet filters based on the current request
     */
    protected function buildAndFacetFilterQuery()
    {
        $filterFacets = $this->query->getAndFacetFilter();
        /** @var null|Criteria $criteria */
        $criteria = null;
        foreach ($this->index->getFacetFields() as $config) {
            if (isset($filterFacets[$config['Title']])) {
                [$filter, $field] = $this->getFieldFacets($filterFacets, $config);
                $this->createFacetCriteria($criteria, $field, $filter);
            }
        }
        if ($criteria) {
            $this->clientQuery
                ->createFilterQuery('andFacets')
                ->setQuery($criteria->getQuery());
        }
    }

    /**
     * Get the field and it's respected values to filter on to generate Criteria from
     *
     * @param array $filterFacets
     * @param array $config
     * @return array
     */
    protected function getFieldFacets(array $filterFacets, $config): array
    {
        $filter = $filterFacets[$config['Title']];
        $filter = is_array($filter) ? $filter : [$filter];
        // Fields are "short named" for convenience
        $shortClass = getShortFieldName($config['BaseClass']);
        $underscoredField = str_replace('.', '_', $config['Field']);
        $field = sprintf('%s_%s', $shortClass, $underscoredField);

        return [$filter, $field];
    }

    /**
     * Combine all facets as AND facet filters for the results
     *
     * @param null|Criteria $criteria
     * @param string $field
     * @param array $filter
     */
    protected function createFacetCriteria(&$criteria, string $field, array $filter)
    {
        // If the criteria is empty, create a new one with a value from the filter array
        if (!$criteria) {
            $criteria = Criteria::where($field)->is(array_pop($filter));
        }
        // Add the other items in the filter array, as an AND
        foreach ($filter as $filterValue) {
            $criteria->andWhere($field)->is($filterValue);
        }
    }

    /**
     * Add OR facet filters based on the current request
     */
    protected function buildOrFacetFilterQuery()
    {
        $filterFacets = $this->query->getOrFacetFilter();
        $index = 0;
        /** @var null|Criteria $criteria */
        foreach ($this->index->getFacetFields() as $config) {
            $criteria = null;
            if (isset($filterFacets[$config['Title']])) {
                [$filter, $field] = $this->getFieldFacets($filterFacets, $config);
                $this->createFacetCriteria($criteria, $field, $filter);
                $this->clientQuery
                    ->createFilterQuery('orFacet-' . $index++)
                    ->setQuery($criteria->getQuery());
            }
        }
    }

    /**
     * Create filter queries
     */
    protected function buildFilters(): void
    {
        $filters = $this->query->getFilter();
        foreach ($filters as $field => $value) {
            $criteria = $this->buildCriteriaFilter($field, $value);
            $this->clientQuery->createFilterQuery('filter-' . $field)
                ->setQuery($criteria->getQuery());
        }
    }

    /**
     * Convert a field/value filter pair to a Criteria object that can build part of a Solr query.
     * If a Criteria object is passed as the value, it will be returned unmodified.
     *
     * @param string $field
     * @param mixed $value
     * @return Criteria
     */
    protected function buildCriteriaFilter(string $field, $value): Criteria
    {
        if ($value instanceof Criteria) {
            return $value;
        }

        $value = (array)$value;

        return Criteria::where($field)->in($value);
    }

    /**
     * Add filtering on canView
     */
    protected function buildViewFilter(): void
    {
        // Filter by what the user is allowed to see
        $viewIDs = ['null']; // null is always an option as that means publicly visible
        $member = Security::getCurrentUser();
        if ($member && $member->exists()) {
            // Member is logged in, thus allowed to see these
            $viewIDs[] = 'LoggedIn';

            /** @var DataList|Group[] $groups */
            $groups = Security::getCurrentUser()->Groups();
            if ($groups->count()) {
                $viewIDs = array_merge($viewIDs, $groups->column('Code'));
            }
        }
        /** Add canView criteria. These are based on {@link DataObjectExtension::ViewStatus()} */
        $query = Criteria::where('ViewStatus')->in($viewIDs);

        $this->clientQuery->createFilterQuery('ViewStatus')
            ->setQuery($query->getQuery());
    }

    /**
     * Add filtered queries based on class hierarchy
     * We only need the class itself, since the hierarchy will take care of the rest
     */
    protected function buildClassFilter(): void
    {
        if (count($this->query->getClasses())) {
            $classes = $this->query->getClasses();
            $criteria = Criteria::where('ClassHierarchy')->in($classes);
            $this->clientQuery->createFilterQuery('classes')
                ->setQuery($criteria->getQuery());
        }
    }

    /**
     * Remove items to exclude
     */
    protected function buildExcludes(): void
    {
        $filters = $this->query->getExclude();
        foreach ($filters as $field => $value) {
            $criteria = $this->buildCriteriaFilter($field, $value);
            $criteria = $criteria->not(); // Negate the filter as we're excluding
            $this->clientQuery->createFilterQuery('exclude-' . $field)
                ->setQuery($criteria->getQuery());
        }
    }
}
