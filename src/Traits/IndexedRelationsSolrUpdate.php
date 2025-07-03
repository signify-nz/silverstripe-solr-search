<?php

namespace Firesphere\SolrSearch\Traits;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

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
     * Called after write. If object is not versioned, reindex related objects.
     *
     * @return void
     */
    public function onAfterWrite(): void
    {
        /** @var DataObject $sourceObject */
        $sourceObject = $this instanceof Extension ? $this->owner : $this;
        $relatedObjects = $this->getIndexedRelations();

        if (!$sourceObject->hasExtension(Versioned::class) && $relatedObjects) {
            $sourceObject->doRelationsReindex($relatedObjects);
        }
    }
    /**
     * Called before delete. Removes references from related objects and reindexes them.
     *
     * @return void
     */
    public function onBeforeDelete(): void
    {
        /** @var DataObject $sourceObject */
        $sourceObject = $this instanceof Extension ? $this->owner : $this;
        $relatedObjects = $this->getIndexedRelations();

        if (empty($relatedObjects) || $sourceObject->hasExtension(Versioned::class)) {
            return;
        }

        $class = get_class($sourceObject);
        $relationsToReindex = [];

        foreach ($relatedObjects as $relatedObject) {
            $relations = array_merge(
                $relatedObject->config()->get('many_many') ?: [],
                $relatedObject->config()->get('has_many') ?: [],
                $relatedObject->config()->get('has_one') ?: [],
                $relatedObject->config()->get('belongs_to') ?: [],
                $relatedObject->config()->get('belongs_many_many') ?: [],
            );

            foreach ($relations as $relationName => $relationClass) {
                if ($relationClass !== $class) {
                    continue;
                }

                $this->handleRelationUpdate($relatedObject, $sourceObject, $relationName, $relationsToReindex);
            }
        }

        $sourceObject->doRelationsReindex($relationsToReindex);
    }

    /**
     * Handles removing or updating the relation between two objects.
     *
     * @param DataObject $relatedObject
     * @param DataObject $sourceObject
     * @param string $relationName
     * @param array $newRelations
     * @return void
     */
    protected function handleRelationUpdate(
        DataObject $relatedObject,
        DataObject $sourceObject,
        string $relationName,
        array &$newRelations
    ): void {
        $relationID = $relationName . 'ID';
        $sourceObjID = $sourceObject->ID ?? null;
        $relatedFieldExists = $relatedObject->hasField($relationID);

        if ($relatedFieldExists && $relatedObject->$relationID == $sourceObjID) {
            $relatedObject->$relationID = 0;
            $this->updateAndTrack($relatedObject, $newRelations);
            return;
        }

        if ($relatedObject->hasMethod($relationName)) {
            $relationList = $relatedObject->$relationName();

            if ($relationList && $relationList->exists() && method_exists($relationList, 'remove')) {
                $relationList->remove($sourceObject);
                $this->updateAndTrack($relatedObject, $newRelations);
                return;
            }

            if ($relationList && $relationList->ID == $sourceObjID && $relatedFieldExists) {
                $relatedObject->$relationID = 0;
                $this->updateAndTrack($relatedObject, $newRelations);
            }
        }
    }

    /**
     * Updates and tracks a related object for reindexing.
     *
     * @param DataObject $relatedObject
     * @param array $newRelations
     * @return void
     */
    protected function updateAndTrack(DataObject $relatedObject, array &$newRelations): void
    {
        $this->setObjectVersion($relatedObject);
        $newRelations[] = $relatedObject;
    }

    /**
     * Writes an object and publishes if versioned and previously published.
     *
     * @param DataObject $sourceObject
     * @return void
     */
    public function setObjectVersion(DataObject $sourceObject): void
    {
        $wasPublished = $sourceObject->hasExtension(Versioned::class) && $sourceObject->isPublished();

        $sourceObject->write();

        if ($wasPublished) {
            $sourceObject->publishSingle();
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
