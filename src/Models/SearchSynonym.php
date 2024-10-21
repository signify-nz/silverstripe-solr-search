<?php
/**
 * class SearchSynonym|Firesphere\SolrSearch\Models\SearchSynonym Object for handling synonyms from the CMS
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 */

namespace Firesphere\SolrSearch\Models;

use Firesphere\SolrSearch\Admins\SearchAdmin;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;

/**
 * Class \Firesphere\SolrSearch\Models\SearchSynonym
 * Manageable synonyms in the CMS
 *
 * @package Firesphere\Solr\Search
 * @property string $Keyword
 * @property string $Synonym
 */
class SearchSynonym extends DataObject implements PermissionProvider
{
    /**
     * @var string Table name
     */
    private static $table_name = 'Solr_SearchSynonym';

    /**
     * @var string Singular name
     */
    private static $singular_name = 'Search synonym';

    /**
     * @var string Plural name
     */
    private static $plural_name = 'Search synonyms';

    /**
     * @var array DB Fields
     */
    private static $db = [
        'Keyword' => 'Varchar(255)',
        'Synonym' => 'Text'
    ];

    /**
     * @var array Summary fields
     */
    private static $summary_fields = [
        'Keyword',
        'Synonym'
    ];

    /**
     * Get the required CMS Fields for this synonym
     *
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->dataFieldByName('Synonym')->setDescription(
            _t(
                __CLASS__ . '.SYNONYM',
                'Create synonyms for a given keyword, add as many synonyms comma separated.'
            )
        );

        return $fields;
    }

    /**
     * Combine this synonym in to a string for the Solr synonyms.txt file
     *
     * @return string
     */
    public function getCombinedSynonym()
    {
        return sprintf("\n%s,%s", $this->Keyword, $this->Synonym);
    }

    /**
     * Member has view access?
     *
     * @param null|Member $member
     * @return bool|mixed
     */
    public function canView($member = null)
    {
        return SearchAdmin::singleton()->canView($member);
    }

    /**
     * Only deleteable by members with permission
     *
     * @param null|Member $member
     * @return bool|mixed
     */
    public function canDelete($member = null)
    {
        return Permission::checkMember($member, 'EDIT_SYNONYMS');
    }

    /**
     * Only createable by members with permission
     *
     * @param null|Member $member
     * @return boolean
     */
    public function canCreate($member = null, $context = [])
    {
        return Permission::checkMember($member, 'EDIT_SYNONYMS');
    }

    /**
     * Only editable by members with permission
     *
     * @param null|Member $member
     * @return boolean
     */
    public function canEdit($member = null)
    {
        return Permission::checkMember($member, 'EDIT_SYNONYMS');
    }

    /**
     * Return a map of permission codes to add to the dropdown shown in the Security section of the CMS.
     * array(
     *   'VIEW_SITE' => 'View the site',
     * );
     *
     * @return array
     */
    public function providePermissions()
    {
        return [
            'EDIT_SYNONYMS'   => [
                'name'     => _t(self::class . '.PERMISSION_EDIT_SYNONYMS_DESCRIPTION', 'Edit Solr synonyms'),
                'category' => _t('Permissions.LOGS_CATEGORIES', 'Solr logs permissions'),
                'help'     => _t(
                    self::class . '.PERMISSION_EDIT_SYNONYMS_HELP',
                    'Permission required to create, edit and delete existing Solr synonyms.'
                ),
            ],
        ];
    }
}
