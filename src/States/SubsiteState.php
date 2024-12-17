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
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\Subsites\State\SubsiteState as BaseSubsiteState;
use Minimalcode\Search\Criteria;
use SilverStripe\Assets\File;

/**
 * Class \Firesphere\SolrSubsites\States\SubsiteState
 *
 * Apply states for Subsites
 *
 * @package Firesphere\Solr\Subsites
 */
class SubsiteState extends SiteState implements SiteStateInterface
{
    /**
     * Combine all results from all subsites
     *
     * @var boolean
     */
    private static $combine_subsite_search = false;

    /**
     * Include main site files in subsite search results if files are indexed
     *
     * @var boolean
     */
    private static $share_main_files = true;

    public const ALL_SUBSITES = 'all';

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
        // Only add a Subsite filter if this hasn't been turned off
        if (!$this->config()->get('combine_subsite_search')) {
            // Show results that match the current subsite ID or are on all subsites
            $filterSubsite = Criteria::where('SubsiteID')
                ->in([BaseSubsiteState::singleton()->getSubsiteId(), static::ALL_SUBSITES]);
            // Unless turned off, include all Files with SubsiteID = 0 regardless of current subsite
            if ($this->config()->get('share_main_files')) {
                $filterSubsite->orWhere(
                    Criteria::where('ClassHierarchy')->contains(File::class)
                        ->andWhere('SubsiteID')->is(0)
                );
            }
            $query->addFilter('SubsiteID', $filterSubsite);
        }
    }
}
