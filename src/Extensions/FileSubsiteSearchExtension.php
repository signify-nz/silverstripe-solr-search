<?php

/**
 * Class FileSubsiteExtension|Firesphere\SolrSearch\Extensions\FileSubsiteExtension
 *
 * @package Firesphere\Solr
 * @author Signify Ltd <info@signify.co.nz>
 * @copyright Copyright (c) 2024 - now() Signify Ltd
 */

namespace Firesphere\SolrSearch\Extensions;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Extension;

/**
 * Class \Firesphere\SolrSearch\Extensions\FileSubsiteSearchExtension
 *
 * Add support for subsites to index Files with SubsiteID from parent Folder
 *
 * @package Firesphere\Solr
 * @property File|FileSubsiteSearchExtension $owner
 */
class FileSubsiteSearchExtension extends Extension
{
    public function getSubsiteID()
    {
        if ($this->owner instanceof Folder) {
            return $this->owner->getField('SubsiteID');
        }
        return $this->owner->ParentID ? $this->owner->Parent()->SubsiteID : $this->owner->getField('SubsiteID');
    }
}
