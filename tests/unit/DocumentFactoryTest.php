<?php


namespace Firesphere\SolrSearch\Tests;

use Firesphere\SolrSearch\Factories\DocumentFactory;
use Firesphere\SolrSearch\Helpers\SearchIntrospection;
use Firesphere\SolrSearch\Indexes\BaseIndex;
use Page;
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use Solarium\Core\Client\Client;
use Solarium\QueryType\Update\Query\Document\Document;
use SilverStripe\View\ViewableData;
use SilverStripe\ORM\DataObject;
use SilverStripe\ErrorPage\ErrorPage;

class DocumentFactoryTest extends SapphireTest
{
    /**
     * We can't use the constant here for unknown reasons
     * If you change the constant, please replace id here with the appropriate value
     * @var array
     */
    protected static $expected_docs = [
        [
            'id'               => 'Page-1',
            'ObjectID'         => 1,
            'ClassName'        => 'Page',
            'ClassHierarchy'   =>
                [
                    'silverstripe\\view\\viewabledata'   => ViewableData::class,
                    'silverstripe\\orm\\dataobject'      => DataObject::class,
                    'silverstripe\\cms\\model\\sitetree' => SiteTree::class,
                    'page'                               => 'Page',
                ],
            'ViewStatus'       =>
                [
                    0 => '1-null',
                ],
            'SiteTree_Title'   => 'Home',
            'SiteTree_Content' => "<p>Welcome to SilverStripe! This is the default homepage. You can edit this page by opening <a href=\"admin/\">the CMS</a>.</p><p>You can now access the <a href=\"http://docs.silverstripe.org\">developer documentation</a>, or begin the <a href=\"http://www.silverstripe.org/learn/lessons\">SilverStripe lessons</a>.</p>",
        ],
        [
            'id'               => 'Page-2',
            'ObjectID'         => 2,
            'ClassName'        => 'Page',
            'ClassHierarchy'   =>
                [
                    'silverstripe\\view\\viewabledata'   => ViewableData::class,
                    'silverstripe\\orm\\dataobject'      => DataObject::class,
                    'silverstripe\\cms\\model\\sitetree' => SiteTree::class,
                    'page'                               => 'Page',
                ],
            'ViewStatus'       =>
                [
                    0 => '1-null',
                ],
            'SiteTree_Title'   => 'About Us',
            'SiteTree_Content' => "<p>You can fill this page out with your own content, or delete it and create your own pages.</p>",
        ],
        [
            'id'               => 'Page-3',
            'ObjectID'         => 3,
            'ClassName'        => 'Page',
            'ClassHierarchy'   =>
                [
                    'silverstripe\\view\\viewabledata'   => ViewableData::class,
                    'silverstripe\\orm\\dataobject'      => DataObject::class,
                    'silverstripe\\cms\\model\\sitetree' => SiteTree::class,
                    'page'                               => 'Page',
                ],
            'ViewStatus'       =>
                [
                    0 => '1-null',
                ],
            'SiteTree_Title'   => 'Contact Us',
            'SiteTree_Content' => "<p>You can fill this page out with your own content, or delete it and create your own pages.</p>",
        ]
    ];

    public function testConstruct()
    {
        $factory = new DocumentFactory();
        $this->assertInstanceOf(SearchIntrospection::class, $factory->getIntrospection());
    }

    public function testBuildItems()
    {
        $factory = new DocumentFactory();
        $index = new TestIndex();
        $fields = $index->getFieldsForIndexing();
        $client = new Client([]);
        $update = $client->createUpdate();
        $factory->setClass(SiteTree::class);
        $factory->setItems(Page::get());
        $docs = $factory->buildItems($fields, $index, $update);

        // Minus 2, the default error pages that should not be indexed
        $this->assertCount(SiteTree::get()->count() - 2, $docs);
        $this->assertInternalType('array', $docs);
        $this->assertInstanceOf(BaseIndex::class, $factory->getIntrospection()->getIndex());
        /** @var Document $doc */
        foreach ($docs as $i => $doc) {
            $this->assertInstanceOf(Document::class, $doc);
            $fields = $doc->getFields();
            unset($fields['SiteTree_Created']); // Unset the Created, it changes per run
            $this->assertEquals(static::$expected_docs[$i], $fields);
        }
    }

    public function testSanitiseField()
    {
        $factory = new DocumentFactory();

        $this->assertEquals('hello', $factory->sanitiseName('Test\\Name\\hello'));
    }

    public function testGetLogger()
    {
        $this->assertInstanceOf(LoggerInterface::class, (new DocumentFactory())->getLogger());
    }
}
