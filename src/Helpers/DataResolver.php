<?php

/**
 * class DataResolver|Firesphere\SolrSearch\Helpers\DataResolver Identify content or relational content of a DataObject
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 * @author Signify Ltd <info@signify.co.nz>
 * Signify Ltd modified code in July 2025
 */

namespace Firesphere\SolrSearch\Helpers;

use LogicException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\SS_List;
use SilverStripe\View\ArrayData;

/**
 * Class DataResolver
 *
 * @package Firesphere\Solr\Search
 */
class DataResolver
{
    /**
     * Component to resolve
     *
     * @var DataObject|ArrayList|SS_List|DBField
     */
    protected $component;
    /**
     * Columns to resolve
     *
     * @var array
     */
    protected $columns = [];
    /**
     * Column to resolve
     *
     * @var mixed|string|null
     */
    protected $columnName = '';

    /**
     * ShortName of a class
     *
     * @var string
     */
    protected $shortName;

    /**
     * Supported object types
     *
     * @var array map of objects to methods
     */
    private static $objTypes = [
        DataObject::class => 'DataObject',
        ArrayData::class  => 'ArrayData',
        SS_List::class    => 'List',
        DBField::class    => 'Field',
    ];

    /**
     * DataResolver constructor.
     *
     * @param DataObject|ArrayList|SS_List|DBField $component
     * @param array|string $columns
     */
    public function __construct($component, $columns = [])
    {
        if (!is_array($columns)) {
            $columns = str_replace('.', '_', $columns);
            $columns = array_filter(explode('_', $columns));
        }
        $this->columns = $columns;
        $this->component = $component;
        $this->columnName = $this->columns ? array_shift($this->columns) : null;
        $this->shortName = ClassInfo::shortName($component);
    }

    /**
     * Identify the given object's columns
     *
     * @param DataObject|ArrayData|SS_List|DBField $obj
     * @param array|string $columns
     *
     * @return mixed
     * @throws LogicException
     */
    public static function identify($obj, $columns = [])
    {
        /** @var {@link self::$objTypes} $type */
        foreach (self::$objTypes as $type => $method) {
            if ($obj instanceof $type) {
                $method = 'resolve' . $method;

                $self = new self($obj, $columns);
                $result = $self->{$method}();
                gc_collect_cycles();

                return $result;
            }
        }

        throw new LogicException(sprintf('Class: %s is not supported.', ClassInfo::shortName($obj)));
    }

    /**
     * An error occured, so log it
     *
     * @param DataObject|ArrayData|SS_List $component
     * @param array $columns
     *
     * @return void
     * @throws LogicException
     */
    protected function cannotIdentifyException($component, $columns = []): void
    {
        throw new LogicException(
            sprintf(
                'Cannot identify, "%s" from class "%s"',
                implode('.', $columns),
                ClassInfo::shortName($component)
            )
        );
    }

    /**
     * Resolves an ArrayData value
     *
     * @return mixed
     * @throws LogicException
     */
    protected function resolveArrayData()
    {
        if (empty($this->columnName)) {
            return $this->component->toMap();
        }
        // Inspect component has attribute
        if (empty($this->columns) && $this->component->hasField($this->columnName)) {
            return $this->component->{$this->columnName};
        }
        $this->cannotIdentifyException($this->component, array_merge([$this->columnName], $this->columns));
    }

    /**
     * Resolves a DataList values
     *
     * @return array|mixed
     * @throws LogicException
     */
    protected function resolveList()
    {
        if (empty($this->columnName)) {
            return $this->component->toNestedArray();
        }
        // Inspect $component for element $relation
        if ($this->component->hasMethod($this->columnName)) {
            $relation = $this->columnName;

            return self::identify($this->component->$relation(), $this->columns);
        }
        $data = [];
        array_unshift($this->columns, $this->columnName);
        foreach ($this->component as $component) {
            $data[] = self::identify($component, $this->columns);
        }

        return $data;
    }

    /**
     * Resolves a Single field in the database.
     *
     * @return mixed
     * @throws LogicException
     */
    protected function resolveField()
    {
        if ($this->columnName) {
            $method = $this->checkHasMethod();

            $value = $this->component->$method();
        } else {
            $value = $this->component->getValue();
        }

        if (!empty($this->columns)) {
            $this->cannotIdentifyException($this->component, $this->columns);
        }

        return $value;
    }

    /**
     * Check if a component has the method instead of it being a property
     *
     * @return null|mixed|string
     * @throws LogicException
     */
    protected function checkHasMethod()
    {
        if ($this->component->hasMethod($this->columnName)) {
            $method = $this->columnName;
        } elseif ($this->component->hasMethod("get{$this->columnName}")) {
            $method = "get{$this->columnName}";
        } else {
            throw new LogicException(
                sprintf('Method, "%s" not found on "%s"', $this->columnName, $this->shortName)
            );
        }

        return $method;
    }

    /**
     * Resolves a DataObject value
     *
     * @return mixed
     * @throws LogicException
     */
    protected function resolveDataObject()
    {
        if (empty($this->columnName)) {
            return $this->component->toMap();
        }
        // Inspect component for element $relation
        if ($this->component->hasMethod($this->columnName)) {
            return $this->getMethodValue();
        }
        // Inspect component has attribute
        if ($this->component->hasField($this->columnName)) {
            return $this->getFieldValue();
        }
        $this->cannotIdentifyException($this->component, [$this->columnName]);
    }

    /**
     * Get the value for a method
     *
     * @return mixed
     */
    protected function getMethodValue()
    {
        $relation = $this->columnName;
        // We hit a direct method that returns a non-object
        if (!is_object($this->component->$relation())) {
            return $this->component->$relation();
        }

        return self::identify($this->component->$relation(), $this->columns);
    }

    /**
     * Get the value for a field
     *
     * @return mixed
     */
    protected function getFieldValue()
    {
        $data = $this->component->{$this->columnName};
        $dbObject = $this->component->dbObject($this->columnName);
        if ($dbObject) {
            $dbObject->setValue($data);

            return self::identify($dbObject, $this->columns);
        }

        return $data;
    }
}
