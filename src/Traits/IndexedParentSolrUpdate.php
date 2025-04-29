<?php

namespace Firesphere\SolrSearch\Traits;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Trait for non-indexed data objects that should trigger an indexed parent to update Solr
 * e.g. elemental blocks on pages
 *
 * @package app
 * @subpackage traits
 */
trait IndexedParentSolrUpdate
{
    /**
     * If not versioned, after write, reindex the parent object
     *
     * @return void
     */
    public function onAfterWrite()
    {
        $object = $this instanceof Extension ? $this->owner : $this;
        if (!$object->hasExtension(Versioned::class) && $parent = $this->getIndexedParent()) {
            $object->doParentReindex($parent);
        }
    }

    /**
     * If versioned, after publish, reindex the parent object
     *
     * @return void
     */
    public function onAfterPublish()
    {
        if ($parent = $this->getIndexedParent()) {
            $object = $this instanceof Extension ? $this->owner : $this;
            $object->doParentReindex($parent);
        }
    }

    /**
     * After delete, reindex the parent object
     *
     * @return void
     */
    public function onAfterDelete()
    {
        if ($parent = $this->getIndexedParent()) {
            $object = $this instanceof Extension ? $this->owner : $this;
            $object->doParentReindex($parent);
        }
    }

    /**
     * Get the parent object that is or leads to the object indexed for search
     *
     * @return DataObject
     */
    abstract public function getIndexedParent();
}
