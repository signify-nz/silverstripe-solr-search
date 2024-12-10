<?php
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
