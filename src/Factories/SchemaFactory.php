<?php

/**
 * class SchemaFactory|Firesphere\SolrSearch\Services\SchemaFactory Base service for generating a schema
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in Nov 2024
 */

namespace Firesphere\SolrSearch\Factories;

use Exception;
use Firesphere\SolrSearch\Helpers\FieldResolver;
use Firesphere\SolrSearch\Helpers\Statics;
use Firesphere\SolrSearch\Services\SolrCoreService;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Model\ModelData;
use Firesphere\SolrSearch\Indexes\BaseIndex;

/**
 * Class SchemaFactory
 *
 * @package Firesphere\Solr\Search
 */
class SchemaFactory extends ModelData
{
    /**
     * @var array Fields that always need to be stored, by Index name
     */
    protected static $storeFields = [];
    /**
     * @var FieldResolver The field resolver to find a field for a class
     */
    protected $fieldResolver;
    /**
     * @var SolrCoreService CoreService to use
     */
    protected $coreService;
    /**
     * @var array Base paths to the template
     */
    protected $baseTemplatePath;
    /**
     * ABSOLUTE Path to template
     *
     * @var string
     */
    protected $template;
    /**
     * Store the value in Solr
     *
     * @var bool
     */
    protected $store = false;
    /**
     * Index to generate the schema for
     *
     * @var BaseIndex
     */
    protected $index;
    /**
     * ABSOLUTE Path to types.ss template
     *
     * @var string
     */
    protected $typesTemplate;

    /**
     * SchemaFactory constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->fieldResolver = Injector::inst()->get(FieldResolver::class);
        $this->coreService = Injector::inst()->get(SolrCoreService::class);
    }

    /**
     * Get all fulltext field definitions that are loaded
     *
     * @return ArrayList
     * @throws Exception
     */
    public function getFulltextFieldDefinitions()
    {
        $return = ArrayList::create();
        $store = $this->store;
        $this->setStore(true);
        foreach ($this->index->getFulltextFields() as $field) {
            $this->getFieldDefinition($field, $return);
        }

        $this->extend('onBeforeFulltextFields', $return);

        $this->setStore($store);

        return $return;
    }

    /**
     * Get the field definition for a single field
     *
     * @param $fieldName
     * @param ArrayList $return
     * @param null|string $copyField
     * @throws Exception
     */
    protected function getFieldDefinition($fieldName, &$return, $copyField = null)
    {
        $field = $this->fieldResolver->resolveField($fieldName);
        $typeMap = Statics::getTypeMap();
        $storeFields = $this->getStoreFields();
        $item = [];
        foreach ($field as $name => $options) {
            // @todo Not-so temporary short-name solution until the Introspection is properly solved
            $name = getShortFieldName($name);
            // Boosted fields are always stored
            $store = ($this->store || in_array($name, $storeFields) ? 'true' : 'false');
            $item = [
                'FieldName'   => $name,
                'Type'        => $typeMap[$options['type']],
                'Indexed'     => 'true',
                'Stored'      => $options['store'] ?? $store,
                'MultiValued' => $options['multi_valued'] ? 'true' : 'false',
                'Destination' => $copyField,
            ];
            $return->push($item);
        }

        $this->extend('onAfterFieldDefinition', $return, $item);
    }

    /**
     * Get the stored fields. This includes boosted and faceted fields
     *
     * @return array
     */
    protected function getStoreFields(): array
    {
        if (isset(static::$storeFields[$this->index->getIndexName()])) {
            return static::$storeFields[$this->index->getIndexName()];
        }

        $boostedFields = $this->index->getBoostedFields();
        $storedFields = $this->index->getStoredFields();
        $facetFields = $this->index->getFacetFields();
        $facetArray = [];
        foreach ($facetFields as $facetField) {
            $facetArray[] = $facetField['BaseClass'] . '.' . $facetField['Field'];
        }

        // Boosts, facets and obviously stored fields need to be stored
        $storeFields = array_merge($storedFields, array_keys($boostedFields), $facetArray);

        foreach ($storeFields as &$field) {
            $field = getShortFieldName(str_replace('.', '_', $field));
        }

        static::$storeFields[$this->index->getIndexName()] = $storeFields;

        return $storeFields;
    }

    /**
     * Get the fields that should be copied
     *
     * @return ArrayList
     */
    public function getCopyFields()
    {
        $fields = $this->index->getCopyFields();

        $return = ArrayList::create();
        $defaultType = SolrCoreService::singleton()->getSolrVersion() === 4 ? 'htmltext' : 'stemfield';
        foreach ($fields as $field => $copyFields) {
            $item = [
                'FieldName' => $field,
                'Type' => (array_key_exists('type', $copyFields) ? $copyFields['type'] : $defaultType)
            ];

            $return->push($item);
        }

        $this->extend('onBeforeCopyFields', $return);

        return $return;
    }

    /**
     * Get the definition of a copy field for determining what to load in to Solr
     *
     * @return ArrayList
     * @throws Exception
     */
    public function getCopyFieldDefinitions()
    {
        $copyFields = $this->index->getCopyFields();

        $return = ArrayList::create();

        foreach ($copyFields as $field => $fields) {
            // Allow all fields to be in a copyfield via a shorthand
            if ($fields[0] === '*') {
                $fields = $this->index->getFulltextFields();
            } else {
                unset($fields['type']);
            }

            foreach ($fields as $copyField) {
                $this->getFieldDefinition($copyField, $return, $field);
            }
        }
        $this->extend('onBeforeCopyFieldDefinitions', $return);
        return $return;
    }

    /**
     * Get the definitions of a filter field to load in to Solr.
     *
     * @return ArrayList
     * @throws Exception
     */
    public function getFilterFieldDefinitions()
    {
        $return = ArrayList::create();
        $originalStore = $this->store;
        // Always store every field in dev mode
        $this->setStore(Director::isDev() ? true : $this->store);
        $fields = $this->index->getFilterFields();
        foreach ($this->index->getFacetFields() as $facetField) {
            $fields[] = $facetField['Field'];
        }
        $fields = array_unique($fields);
        foreach ($fields as $field) {
            $this->getFieldDefinition($field, $return);
        }
        $this->extend('onBeforeFilterFields', $return);

        $this->setStore($originalStore);

        return $return;
    }

    /**
     * Get the types template in a rendered state
     *
     * @return DBHTMLText
     */
    public function getTypes()
    {
        $template = $this->getTemplatePathFor('schema');
        $this->setTypesTemplate($template . '/types.ss');

        return $this->renderWith($this->getTypesTemplate());
    }

    /**
     * Get the base path of the template given, e.g. the "schema" templates
     * or the "extra" templates.
     *
     * @param string $type What type of templates do we need to get
     * @return string
     */
    public function getTemplatePathFor($type): string
    {
        $template = $this->getBaseTemplatePath($type);

        // If the template is set, return early
        // Explicitly check for boolean. If it's a boolean,
        // the template needs to be resolved
        if (!is_bool($template)) {
            return $template;
        }
        $templatePath = SolrCoreService::config()->get('paths');
        $customPath = $templatePath['base_path'] ?? false;
        $path = ModuleLoader::getModule('signify-nz/silverstripe-solr-search')->getPath();

        if ($customPath) {
            $path = sprintf($customPath, Director::baseFolder());
        }

        $solrVersion = $this->coreService->getSolrVersion();
        $template = sprintf($templatePath[$solrVersion][$type], $path);
        $this->setBaseTemplatePath($template, $type);

        return $template;
    }

    /**
     * Retrieve the base template path for a type (extra or schema)
     *
     * @param $type
     * @return string|bool
     */
    public function getBaseTemplatePath($type)
    {
        return $this->baseTemplatePath[$type] ?? false;
    }

    /**
     * Set the base template path for a type (extra or schema)
     *
     * @param string $baseTemplatePath
     * @param string $type
     * @return SchemaFactory
     */
    public function setBaseTemplatePath(string $baseTemplatePath, string $type): SchemaFactory
    {
        $this->baseTemplatePath[$type] = $baseTemplatePath;

        return $this;
    }

    /**
     * Generate the Schema xml
     *
     * @return DBHTMLText
     */
    public function generateSchema()
    {
        $template = $this->getTemplatePathFor('schema');
        $this->setTemplate($template . '/schema.ss');

        return $this->renderWith($this->getTemplate());
    }

    /**
     * Get any the template path for anything that needs loading in to Solr
     *
     * @return string
     */
    public function getExtrasPath()
    {
        return $this->getTemplatePathFor('extras');
    }
    /**
     * Set the store value
     *
     * @param bool $store
     */
    public function setStore(bool $store): void
    {
        $this->store = $store;
    }

    /**
     * Get the Index that's being used
     *
     * @return BaseIndex
     */
    public function getIndex()
    {
        return $this->index;
    }

    /**
     * Set the index that's being used and add the introspection for it
     *
     * @param BaseIndex $index
     * @return SchemaFactory
     */
    public function setIndex($index): self
    {
        $this->index = $index;
        // Add the index to the introspection as well, there's no need for a separate call here
        // We're loading this core, why would we want the introspection from a different index?
        $this->fieldResolver->setIndex($index);

        return $this;
    }

    /**
     * Get the name of the index being used
     *
     * @return string
     */
    public function getIndexName(): string
    {
        return $this->index->getIndexName();
    }

    /**
     * Get the default field to generate df components for
     *
     * @return string|array
     */
    public function getDefaultField()
    {
        return $this->index->getDefaultField();
    }

    /**
     * Get the Identifier Field for Solr
     *
     * @return string
     */
    public function getIDField(): string
    {
        return SolrCoreService::ID_FIELD;
    }

    /**
     * Get the Identifier Field for Solr
     *
     * @return string
     */
    public function getClassID(): string
    {
        return SolrCoreService::CLASS_ID_FIELD;
    }

    /**
     * Get the types template if defined
     *
     * @return string
     */
    public function getTypesTemplate()
    {
        return $this->typesTemplate;
    }

    /**
     * Set custom types template
     *
     * @param string $typesTemplate
     * @return SchemaFactory
     */
    public function setTypesTemplate($typesTemplate): self
    {
        $this->typesTemplate = $typesTemplate;

        return $this;
    }

    /**
     * Get the base template for the schema xml
     *
     * @return string
     */
    public function getTemplate()
    {
        return $this->template;
    }

    /**
     * Set a custom template for schema xml
     *
     * @param string $template
     * @return SchemaFactory
     */
    public function setTemplate($template): self
    {
        $this->template = $template;

        return $this;
    }
}
