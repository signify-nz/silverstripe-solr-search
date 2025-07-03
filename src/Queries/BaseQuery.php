<?php

/**
 * class BaseQuery|Firesphere\SolrSearch\Queries\BaseQuery Base of a Solr Query
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in July 2025
 */

namespace Firesphere\SolrSearch\Queries;

use Firesphere\SolrSearch\Traits\GetterSetterTrait;
use SilverStripe\Core\Injector\Injectable;
use Minimalcode\Search\Criteria;

/**
 * Class BaseQuery is the base of every query executed.
 *
 * Build a query to execute agains Solr. Uses as simle as possible an interface.
 *
 * @package Firesphere\Solr\Search
 */
class BaseQuery
{
    use GetterSetterTrait;
    use Injectable;

    /**
     * @var int Pagination start
     */
    protected $start = 0;
    /**
     * @var int Total rows to display
     */
    protected $rows = 10;
    /**
     * @var array Always get the ID. If you don't, you need to implement your own solution
     */
    protected $fields = [];
    /**
     * @var array Sorting settings
     */
    protected $sort = [];
    /**
     * @var bool Enable spellchecking?
     */
    protected $spellcheck = true;
    /**
     * @var bool Follow spellchecking if there are no results
     */
    protected $followSpellcheck = false;
    /**
     * @var int Minimum results a facet query has to have
     */
    protected $facetsMinCount = 1;
    /**
     * @var array Search terms
     */
    protected $terms = [];
    /**
     * @var array Highlighted items
     */
    protected $highlight = [];
    /**
     * @var array Fields to filter
     */
    protected $filter = [];
    /**
     * Key => value pairs of facets to apply in AND fashion
     * [
     *     'FacetTitle' => [1, 2, 3],
     *     'FacetTitle2' => [1, 2, 3]
     * ]
     *
     * @var array
     */
    protected $andFacetFilter = [];
    /**
     * Key => value pairs of facets to apply in OR fashion
     * [
     *     'FacetTitle' => [1, 2, 3],
     *     'FacetTitle2' => [1, 2, 3]
     * ]
     *
     * @var array
     */
    protected $orFacetFilter = [];
    /**
     * @var array Fields to exclude
     */
    protected $exclude = [];

    /**
     * Get the offset to start
     *
     * @return int
     */
    public function getStart(): int
    {
        return $this->start;
    }

    /**
     * Set the offset to start
     *
     * @param int $start
     * @return $this
     */
    public function setStart($start): self
    {
        $this->start = $start;

        return $this;
    }

    /**
     * Get the rows to return
     *
     * @return int
     */
    public function getRows(): int
    {
        return $this->rows;
    }

    /**
     * Set the rows to return
     *
     * @param int $rows
     * @return $this
     */
    public function setRows($rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    /**
     * Get the fields to return
     *
     * @return array
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Set fields to be returned
     *
     * @param array $fields
     * @return $this
     */
    public function setFields($fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Get the sort fields
     *
     * @return array
     */
    public function getSort(): array
    {
        return $this->sort;
    }

    /**
     * Set the sort fields
     *
     * @param array $sort
     * @return $this
     */
    public function setSort($sort): self
    {
        $this->sort = $sort;

        return $this;
    }

    /**
     * Get the facet count minimum to use
     *
     * @return int
     */
    public function getFacetsMinCount(): int
    {
        return $this->facetsMinCount;
    }

    /**
     * Set the minimum count of facets to be returned
     *
     * @param mixed $facetsMinCount
     * @return $this
     */
    public function setFacetsMinCount($facetsMinCount): self
    {
        $this->facetsMinCount = $facetsMinCount;

        return $this;
    }

    /**
     * Get the search terms
     *
     * @return array
     */
    public function getTerms(): array
    {
        return $this->terms;
    }

    /**
     * Set the search tearms
     *
     * @param array $terms
     * @return $this
     */
    public function setTerms($terms): self
    {
        $this->terms = $terms;

        return $this;
    }

    /**
     * Get the filters
     *
     * @return array
     */
    public function getFilter(): array
    {
        return $this->filter;
    }

    /**
     * Set the query filters
     *
     * @param array $filter
     * @return $this
     */
    public function setFilter($filter): self
    {
        $this->filter = $filter;

        return $this;
    }

    /**
     * Get the excludes
     *
     * @return array
     */
    public function getExclude(): array
    {
        return $this->exclude;
    }

    /**
     * Set the query excludes
     *
     * @param array $exclude
     * @return $this
     */
    public function setExclude($exclude): self
    {
        $this->exclude = $exclude;

        return $this;
    }

    /**
     * Add a highlight parameter
     *
     * @param $field
     * @return $this
     */
    public function addHighlight($field): self
    {
        $this->highlight[] = $field;

        return $this;
    }

    /**
     * Get the highlight parameters
     *
     * @return array
     */
    public function getHighlight(): array
    {
        return $this->highlight;
    }

    /**
     * Set the highlight parameters
     *
     * @param array $highlight
     * @return $this
     */
    public function setHighlight($highlight): self
    {
        $this->highlight = $highlight;

        return $this;
    }

    /**
     * Do we have spellchecking
     *
     * @return bool
     */
    public function hasSpellcheck(): bool
    {
        return $this->spellcheck;
    }

    /**
     * Set the spellchecking on this query
     *
     * @param bool $spellcheck
     * @return self
     */
    public function setSpellcheck(bool $spellcheck): self
    {
        $this->spellcheck = $spellcheck;

        return $this;
    }

    /**
     * Set if we should follow spellchecking
     *
     * @param bool $followSpellcheck
     * @return BaseQuery
     */
    public function setFollowSpellcheck(bool $followSpellcheck): BaseQuery
    {
        $this->followSpellcheck = $followSpellcheck;

        return $this;
    }

    /**
     * Should spellcheck suggestions be followed
     *
     * @return bool
     */
    public function shouldFollowSpellcheck(): bool
    {
        return $this->followSpellcheck;
    }

    /**
     * Stub for AND facets to be get
     *
     * @return array
     */
    public function getAndFacetFilter(): array
    {
        return $this->getFacetFilter();
    }

    /**
     * Get the AND facet filtering
     *
     * @return array
     */
    public function getFacetFilter(): array
    {
        return $this->andFacetFilter;
    }

    /**
     * Stub for AND facets to be set
     *
     * @param array $facetFilter
     * @return BaseQuery
     */
    public function setAndFacetFilter(array $facetFilter): self
    {
        return $this->setFacetFilter($facetFilter);
    }

    /**
     * Set the AND based facet filtering
     *
     * @param array $facetFilter
     * @return BaseQuery
     */
    public function setFacetFilter(array $facetFilter): self
    {
        $this->andFacetFilter = $facetFilter;

        return $this;
    }

    /**
     * Get the OR based facet filtering
     *
     * @return array
     */
    public function getOrFacetFilter(): array
    {
        return $this->orFacetFilter;
    }

    /**
     * Set the OR based facet filtering
     *
     * @param array $facetFilter
     * @return BaseQuery
     */
    public function setOrFacetFilter(array $facetFilter): self
    {
        $this->orFacetFilter = $facetFilter;

        return $this;
    }

    /**
     * Each boosted query needs a separate addition!
     * e.g. $this->addTerm('test', ['MyField', 'MyOtherField'], 3)
     * followed by
     * $this->addTerm('otherTest', ['Title'], 5);
     *
     * If you want a generic boost on all terms, use addTerm only once, but boost on each field
     *
     * The fields parameter is used to boost on
     *
     * For generic boosting, use @addBoostedField($field, $boost), this will add the boost at Index time
     *
     * @param string $term Term to search for
     * @param array $fields fields to boost on
     * @param int $boost Boost value
     * @param bool|float $fuzzy True or a value to the maximum amount of iterations
     * @return $this
     */
    public function addTerm(string $term, array $fields = [], int $boost = 0, $fuzzy = null): self
    {
        $this->terms[] = [
            'text'   => $term,
            'fields' => $fields,
            'boost'  => $boost,
            'fuzzy'  => $fuzzy,
        ];

        return $this;
    }

    /**
     * Adds filters to filter on by value
     *
     * @param string $field Field to filter on
     * @param string|array|Criteria $value Value for this field
     * @return $this
     */
    public function addFilter($field, $value): self
    {
        $field = str_replace('.', '_', $field);
        $this->filter[$field] = $value;

        return $this;
    }

    /**
     * Add a field to be returned
     *
     * @param string $field fieldname
     * @return $this
     */
    public function addField($field): self
    {
        $field = str_replace('.', '_', $field);
        $this->fields[] = $field;

        return $this;
    }

    /**
     * Exclude fields from the search action
     *
     * @param string $field
     * @param string|array|Criteria $value
     * @return $this
     */
    public function addExclude($field, $value): self
    {
        $field = str_replace('.', '_', $field);
        $this->exclude[$field] = $value;

        return $this;
    }

    /**
     * Stub for addFacetFilter to add an AND filter
     *
     * @param string $field
     * @param string|array $value
     * @return $this
     */
    public function addAndFacetFilter($field, $value): self
    {
        return $this->addFacetFilter($field, $value);
    }

    /**
     * Add faceting fields that need to be faceted in an AND format
     *
     * @param string $field Field to facet
     * @param string|array $value Value to facet
     * @return $this
     */
    public function addFacetFilter($field, $value): self
    {
        $value = is_array($value) ? $value : [$value];
        foreach ($value as $item) {
            $this->andFacetFilter[$field][] = $item;
        }

        return $this;
    }

    /**
     * Add faceting that need to be faceted in an OR formats
     *
     * @param string $field Field to facet
     * @param string|array $value Value to facet
     * @return $this
     */
    public function addOrFacetFilter($field, $value): self
    {
        $value = is_array($value) ? $value : [$value];
        foreach ($value as $item) {
            $this->orFacetFilter[$field][] = $item;
        }

        return $this;
    }

    /**
     * Add a field to sort on
     *
     * @param string $field
     * @param string $direction
     * @return $this
     */
    public function addSort($field, $direction): self
    {
        $this->sort[$field] = $direction;

        return $this;
    }
}
