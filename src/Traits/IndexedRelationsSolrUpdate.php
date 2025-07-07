<?php

namespace Firesphere\SolrSearch\Traits;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * Trait for non-indexed objects that should trigger indexed related objects to update Solr.
 * For example, a taxonomy term used on a page.
 *
 * @package app
 * @subpackage traits
 */
trait IndexedRelationsSolrUpdate
{
    /**
     * Related objects
     *
     * @var array
     */
    private $relations;

    /**
     * Called after write. Reindex related objects.
     *
     * @return void
     */
    public function onAfterWrite(): void
    {
        /** @var DataObject $sourceObject */
        $sourceObject = $this instanceof Extension ? $this->owner : $this;
        $relatedObjects = $this->getIndexedRelations() ?? null;

        if ($relatedObjects) {
            $sourceObject->doRelationsReindex($relatedObjects);
        }
    }

    /**
     * Called before delete. Sets related objects to $relations variable.
     *
     * @return void
     */
    public function onBeforeDelete(): void
    {
        $relatedObjects = $this->getIndexedRelations();
        $this->relations = $relatedObjects->toArray();
    }

    /**
     * Called after delete. Reindex related objects.
     *
     * @return void
     */
    public function onAfterDelete(): void
    {
        /** @var DataObject $sourceObject */
        $sourceObject = $this instanceof Extension ? $this->owner : $this;
        $relatedObjects = $this->relations ?? null;

        if ($relatedObjects) {
            $sourceObject->doRelationsReindex($relatedObjects);
        }
    }

    /**
     * Get the related objects of an object that are indexed for search.
     * This must return an array or iterable list of DataObject instances that will be reindexed.
     *
     * @return DataList|array
     */
    abstract public function getIndexedRelations();
}
