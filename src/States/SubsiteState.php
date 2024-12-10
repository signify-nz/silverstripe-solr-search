<?php
/**
 * Class SubsiteState|Firesphere\SolrSearch\States\SubsiteState Enable each subsite to be indexed independently by
 * switching the SiteState
 * {@see \Firesphere\SolrSearch\States\SiteState} and {@see \Firesphere\SolrSearch\Interfaces\SiteStateInterface}
 *
 * @package Firesphere\Solr\Subsites
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in Dec 2024
 */


namespace Firesphere\SolrSearch\States;

use Firesphere\SolrSearch\Interfaces\SiteStateInterface;
use Firesphere\SolrSearch\Queries\BaseQuery;
use Firesphere\SolrSearch\States\SiteState;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\Subsites\State\SubsiteState as BaseSubsiteState;

/**
 * Class \Firesphere\SolrSubsites\States\SubsiteState
 *
 * Apply states for Subsites
 *
 * @package Firesphere\Solr\Subsites
 */
class SubsiteState extends SiteState implements SiteStateInterface
{
    private static $combine_subsite_search = false;

    public function isEnabled(): bool
    {
        return class_exists(Subsite::class) && $this->enabled;
    }

    /**
     * Is this state applicable to this extension
     * In case of subsites, only apply if there actually are subsites
     *
     * @param int|string $state
     * @return bool
     */
    public function stateIsApplicable($state): bool
    {
        return Subsite::get()->byID($state) !== null && $state !== 0;
    }

    /**
     * Reset the SiteState to it's default state
     * In case of subsites, we don't care about it, as it's handled at query time
     *
     * @param string|null $state
     * @return mixed
     */
    public function setDefaultState($state = null)
    {
        singleton(BaseSubsiteState::class)->setUseSessions(true);
        Subsite::changeSubsite($state);
    }

    /**
     * Return the current state of the site
     * The current state does not need to be reset in any way for pages
     *
     * @return string|null
     */
    public function currentState()
    {
        $subsite = Subsite::currentSubsite();
        return $subsite ? $subsite->ID : null;
    }

    /**
     * Activate a given state. This should only be done if the state is applicable
     * In the case of Subsites, we just want to disable the filter
     *
     * @param int $state
     * @return void
     */
    public function activateState($state)
    {
        Subsite::changeSubsite($state);
    }

    /**
     * Method to alter the query. Can be no-op.
     *
     * @param BaseQuery $query
     * @return void
     */
    public function updateQuery(&$query)
    {
        // Only add a Subsite filter if there are actually subsites to filter on
        if (!$this->config()->get('combine_subsite_search')) {
            $query->addFilter('SubsiteID', BaseSubsiteState::singleton()->getSubsiteId());
        }
    }
}
