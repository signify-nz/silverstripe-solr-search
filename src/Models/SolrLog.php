<?php
/**
 * class SolrLog|Firesphere\SolrSearch\Models\SolrLog Solr logging to be read from the CMS
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in Oct 2024
 */

namespace Firesphere\SolrSearch\Models;

use DateInterval;
use DateTime;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;

/**
 * Class \Firesphere\SolrSearch\Models\SolrError
 *
 * @package Firesphere\Solr\Search
 * @property string $Timestamp
 * @property string $Index
 * @property string $Type
 * @property string $Level
 * @property string $Message
 */
class SolrLog extends DataObject implements PermissionProvider
{
    /**
     * @var array Used to give the Gridfield rows a corresponding colour
     */
    protected static $row_color = [
        'ERROR' => 'alert alert-danger',
        'WARN'  => 'alert alert-warning',
        'INFO'  => 'alert alert-info',
    ];
    /**
     * @var string Database table name
     */
    private static $table_name = 'Solr_SolrLog';
    /**
     * @var array Database columns
     */
    private static $db = [
        'Timestamp' => 'Datetime',
        'Index'     => 'Varchar(255)',
        'Type'      => 'Enum("Config,Index,Query")',
        'Level'     => 'Varchar(10)',
        'Message'   => 'Text',
    ];
    /**
     * @var array Summary fields
     */
    private static $summary_fields = [
        'Timestamp',
        'Index',
        'Type',
        'Level',
    ];
    /**
     * @var array Searchable fields
     */
    private static $searchable_fields = [
        'Created',
        'Timestamp',
        'Index',
        'Type',
        'Level',
    ];
    /**
     * @var array Timestamp is indexed
     */
    private static $indexes = [
        'Timestamp' => true,
    ];
    /**
     * @var string Default sort
     */
    private static $default_sort = 'Timestamp DESC';

    /**
     * Convert the Timestamp to a DBDatetime for compatibility
     */
    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        $this->Timestamp = DBDatetime::create()->setValue(strtotime($this->Timestamp));
    }

    /**
     * Return the first line of this log item error
     *
     * @return string
     */
    public function getLastErrorLine()
    {
        $lines = explode(PHP_EOL, $this->Message);

        return $lines[0];
    }

    /**
     * Not creatable by users
     *
     * @param null|Member $member
     * @param array $context
     * @return bool|mixed
     */
    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    /**
     * Not editable by users
     *
     * @param null|Member $member
     * @return bool|mixed
     */
    public function canEdit($member = null)
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
        return Permission::checkMember($member, 'VIEW_LOG');
    }

    /**
     * Only deleteable by members with permission or when in dev mode to clean up
     *
     * @param null|Member $member
     * @return bool|mixed
     */
    public function canDelete($member = null)
    {
        return Permission::checkMember($member, 'DELETE_LOG') || Director::isDev();
    }

    /**
     * Get the extra classes to colour the gridfield rows
     *
     * @return mixed|string
     */
    public function getExtraClass()
    {
        $classMap = static::$row_color;

        return $classMap[$this->Level] ?? 'alert alert-info';
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
            'DELETE_LOG' => [
                'name'     => _t(self::class . '.PERMISSION_DELETE_DESCRIPTION', 'Delete Solr logs'),
                'category' => _t('Permissions.LOGS_CATEGORIES', 'Solr logs permissions'),
                'help'     => _t(
                    self::class . '.PERMISSION_DELETE_HELP',
                    'Permission required to delete existing Solr logs.'
                ),
            ],
            'VIEW_LOG'   => [
                'name'     => _t(self::class . '.PERMISSION_VIEW_DESCRIPTION', 'View Solr logs'),
                'category' => _t('Permissions.LOGS_CATEGORIES', 'Solr logs permissions'),
                'help'     => _t(
                    self::class . '.PERMISSION_VIEW_HELP',
                    'Permission required to view existing Solr logs.'
                ),
            ],
        ];
    }


    /**
     * Delete logs older than a configurable date.
     *
     * @return int The number of logs deleted.
     */
    public static function truncateLogs()
    {
        $tableName = DataObject::getSchema()->tableName(self::class);
        $deletionSchedule = Config::inst()->get(self::class, 'deletion_period');
        $logger = Injector::inst()->get(LoggerInterface::class);

        if (!is_int($deletionSchedule) || $deletionSchedule < 0) {
            $logger->info('The value of "deletion_period" is invalid, must be an integer >= 0 to trigger log truncation.');
            return 0;
        };

        $deleteDate = (new DateTime())->sub(DateInterval::createFromDateString("{$deletionSchedule} days"))->format(DateTime::ATOM);

        $logger->info(_t(
            __class__ . '.CLEARLOG',
            'Emptying logs for table ' . $tableName . PHP_EOL
        ));


        $logs = SolrLog::get()->filter(['Created:LessThan' => $deleteDate]);
        $count = $logs->count();
        if ($count) {
            $logger->info('Deleting ' . $count . ' logs from the database.');
            $query = SQLDelete::create([$tableName]);
            $query->addWhere(['Created < ?' => $deleteDate]);
            $query->execute();
        } else {
            $logger->info('No logs were found older than the deletion date.');
        }
        return $count;
    }
}
