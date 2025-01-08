<?php

/**
 * class SearchAdmin|Firesphere\SolrSearch\Admins\SearchAdmin Base admin for Synonyms, logs and dirty classes
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in Nov 2024
 */

namespace Firesphere\SolrSearch\Admins;

use Firesphere\SolrSearch\Models\DirtyClass;
use Firesphere\SolrSearch\Models\SearchSynonym;
use Firesphere\SolrSearch\Models\SolrLog;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\LiteralField;
use SilverStripe\View\Requirements;

/**
 * Class \Firesphere\SolrSearch\Admins\SearchAdmin
 * Manage or see the Solr configuration. Default implementation of SilverStripe ModelAdmin
 * Nothing to see here
 *
 * @package Firesphere\Solr\Search
 */
class SearchAdmin extends ModelAdmin
{
    /**
     * @var array Models managed by this admin
     */
    private static $managed_models = [
        SearchSynonym::class,
        SolrLog::class,
        DirtyClass::class,
    ];

    /**
     * @var string Add a pretty magnifying glass to the sidebar menu
     */
    private static $menu_icon_class = 'font-icon-search';

    /**
     * @var string Where to find me
     */
    private static $url_segment = 'searchadmin';

    /**
     * @var string My name
     */
    private static $menu_title = 'Search';

    /**
     * Make sure the custom CSS for highlighting in the GridField is loaded
     */
    public function init()
    {
        parent::init();

        Requirements::css('signify-nz/silverstripe-solr-search:client/dist/main.css');
    }

    /**
     * Add help text above gridfield in search synonyms admin tab.
     *
     * @param int $id
     * @param FieldList $fields
     * @return Form
     */
    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        $helpText = LiteralField::create(
            'SearchSynonymHelpText',
            '<p style="padding-bottom: 1rem;">To have any changes reflected in the search, the SolrConfigureJob needs to be executed.
            This job is automatically added to the queue when creating, updating, or removing a synonym,
            and will display in search after the job queue is processed.</p>'
        );

        $form->Fields()->insertBefore('Firesphere-SolrSearch-Models-SearchSynonym', $helpText);

        return $form;
    }

    protected function getManagedModelTabs()
    {
        $tabs = parent::getManagedModelTabs();
        return $tabs->filterByCallback(function ($tab) {
            return singleton($tab->ClassName)->canView();
        });
    }
}
