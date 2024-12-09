<?php
/**
 * class DirtyClass|Firesphere\SolrSearch\Models\DirtyClass Store Dirty classes for re-indexing
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in Oct 2024
 */

namespace Firesphere\SolrSearch\Models;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;

/**
 * Class \Firesphere\SolrSearch\Models\DirtyClass
 * Keeping track of Dirty classes in Solr
 *
 * @package Firesphere\Solr\Search
 * @property string $Type
 * @property string $Class
 * @property string $IDs
 */
class DirtyClass extends DataObject implements PermissionProvider
{
    /**
     * @var string Table name
     */
    private static $table_name = 'Solr_DirtyClass';
    /**
     * @var string Singular name
     */
    private static $singular_name = 'Dirty class';
    /**
     * @var string Plural name
     */
    private static $plural_name = 'Dirty classes';
    /**
     * @var array Database fields
     */
    private static $db = [
        'Type'  => 'Varchar(6)',
        'Class' => 'Varchar(512)',
        'IDs'   => 'Varchar(255)',
    ];
    /**
     * @var array Summary fields in CMS
     */
    private static $summary_fields = [
        'Class',
        'Type',
        'IDs',
    ];

    /**
     * Make the CMS fields readable
     *
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Class', 'IDs']);

        $class = singleton($this->Class)->plural_name();

        $IDs = json_decode($this->IDs, true);

        $fields->addFieldsToTab('Root.Main', [
            ReadonlyField::create('Class', 'Class', $class),
            ReadonlyField::create('IDs', _t(self::class . '.DIRTYIDS', 'Dirty IDs'), $IDs),
        ]);

        return $fields;
    }

    /**
     * Nope, can't delete these
     *
     * @param null|Member $member
     * @return bool
     */
    public function canDelete($member = null)
    {
        return false;
    }

    /**
     * Nope, can't edit these
     *
     * @param null|Member $member
     * @return bool
     */
    public function canEdit($member = null)
    {
        return false;
    }

    /**
     * Nope, can't create these
     *
     * @param null|Member $member
     * @param array $context
     * @return bool
     */
    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    /**
     * Member has view access?
     *
     * @param null|Member $member
     * @return bool|mixed
     */
    public function canView($member = null)
    {
        return Permission::checkMember($member, 'VIEW_DIRTY_CLASSES');
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
            'VIEW_DIRTY_CLASSES'   => [
                'name'     => _t(self::class . '.PERMISSION_VIEW_CLASSES_DESCRIPTION', 'View Solr dirty classes'),
                'category' => _t('Permissions.LOGS_CATEGORIES', 'Solr logs permissions'),
                'help'     => _t(
                    self::class . '.PERMISSION_VIEW_CLASSES_HELP',
                    'Permission required to view existing Solr dirty classes.'
                ),
            ],
        ];
    }
}
