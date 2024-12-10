<?php

/**
 * Class SubsitesDocumentFactoryExtension|Firesphere\SolrSearch\Extensions\SubsitesDocumentFactoryExtension
 *
 * @package Firesphere\Solr
 * @author Signify Ltd <info@signify.co.nz>
 * @copyright Copyright (c) 2024 - now() Signify Ltd
 */

namespace Firesphere\SolrSearch\Extensions;

use Firesphere\SolrSearch\States\SubsiteState;
use SilverStripe\Core\Extension;

/**
 * Update Documents per locale
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
