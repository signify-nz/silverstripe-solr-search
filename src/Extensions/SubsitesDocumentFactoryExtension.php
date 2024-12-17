<?php

/**
 * Class SubsitesDocumentFactoryExtension|Firesphere\SolrSearch\Extensions\SubsitesDocumentFactoryExtension
 *
 * @package Firesphere\Solr
 * @author Signify Ltd <info@signify.co.nz>
 * @copyright Copyright 2024 Signify Ltd
 */

namespace Firesphere\SolrSearch\Extensions;

use Firesphere\SolrSearch\Factories\DocumentFactory;
use Firesphere\SolrSearch\States\SubsiteState;
use SilverStripe\Core\Extension;

/**
 * Class \Firesphere\SolrSearch\Extensions\SubsitesDocumentFactoryExtension
 *
 * Add support for indexed classes without a SubsiteID field to appear on all subsites
 *
 * @package Firesphere\Solr
 * @property DocumentFactory|SubsitesDocumentFactoryExtension $owner
 */
class SubsitesDocumentFactoryExtension extends Extension
{
    /**
     * Update the SubsiteID field for classes that are not linked to a subsite
     *
     * @param array $field
     * @param string $value
     */
    public function onBeforeAddDoc(&$field, &$value, &$object): void
    {
        if ($field['field'] === 'SubsiteID') {
            if ($object->SubsiteID === null) {
                $value = SubsiteState::ALL_SUBSITES;
            }
        }
    }
}
