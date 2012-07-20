<?php

/*
 * This file is part of the DataGridBundle.
 *
 * (c) Abhoryo <abhoryo@free.fr>
 * (c) Stanislav Turza
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace APY\DataGridBundle\Grid\Source;

use APY\DataGridBundle\Grid\Column;
use APY\DataGridBundle\Grid\Rows;
use APY\DataGridBundle\Grid\Row;

/**
 * Vector is really an Array
 * @author dellamowica
 */
class Vector extends Source
{
    /**
     * @var array
     */
    protected $data = array();

    /**
     * either a column name as a string
     *  or an array of names of columns
     * @var mixed
     */
    protected $id = null;

    /**
     * Array of columns
     * @var Column[]
     */
    protected $columns;

    /**
     * Creates the Vector and sets its data
     * @param array $data
     */
    public function __construct(array $data, array $columns = array())
    {
        if (!empty($data)) {
            $this->setData($data);
        }

        $this->setColumns($columns);
    }

    public function initialise($container)
    {
        if (!empty($this->data)) {
            $this->guessColumns();
        }
    }

    protected function guessColumns()
    {
        $columns = array();
        $dataColumnIds = array_keys(reset($this->data));

        foreach ($dataColumnIds as $id) {
            if (!$this->hasColumn($id)) {
                $params = array(
                    'id' => $id,
                    'title' => $id,
                    'source' => true,
                    'filterable' => true,
                    'sortable' => true,
                    'visible' => true,
                    'field' => $id,
                );
                $columns[] = new Column\UntypedColumn($params);
            } else {
                $columns[] = $this->getColumn($id);
            }
        }

        $this->setColumns($columns);

        // Guess on the first 10 rows only
        $iteration = min(10, count($this->data));

        foreach ($this->columns as $c) {
            if (!$c instanceof Column\UntypedColumn) {
                continue;
            }

            $i = 0;
            $fieldTypes = array();

            foreach ($this->data as $row) {
                $fieldValue = $row[$c->getId()];

                if ($fieldValue !== '' && $fieldValue !== null) {
                    if (is_array($fieldValue)) {
                        $fieldTypes['array'] = 1;
                    } elseif (strlen($fieldValue) >= 3 && strtotime($fieldValue) !== false) {
                        $dt = new \DateTime($fieldValue);
                        if ($dt->format('His') === '000000') {
                            $fieldTypes['date'] = 1;
                        } else {
                            $fieldTypes['datetime'] = 1;
                        }
                    } elseif (true === $fieldValue || false === $fieldValue || 1 === $fieldValue || 0 === $fieldValue || '1' === $fieldValue || '0' === $fieldValue) {
                        $fieldTypes['boolean'] = 1;
                    } elseif (is_numeric($fieldValue)) {
                        $fieldTypes['number'] = 1;
                    } else {
                        $fieldTypes['text'] = 1;
                    }
                }

                if (++$i >= $iteration) {
                    break;
                }
            }

            if(count($fieldTypes) == 1) {
                $c->setType(key($fieldTypes));
            } elseif (isset($fieldTypes['boolean']) && isset($fieldTypes['number'])) {
                $c->setType('number');
            } elseif (isset($fieldTypes['date']) && isset($fieldTypes['datetime'])) {
                $c->setType('datetime');
            } else {
                $c->setType('text');
            }
        }
    }

    /**
     * @param \APY\DataGridBundle\Grid\Columns $columns
     * @return null
     */
    public function getColumns($columns)
    {
        $token = empty($this->id); //makes the first column primary by default

        foreach ($this->columns as $c) {
            if ($c instanceof Column\UntypedColumn) {
                switch ($c->getType()) {
                    case 'date':
                        $column = new Column\DateColumn($c->getParams());
                        break;
                    case 'datetime':
                        $column = new Column\DateTimeColumn($c->getParams());
                        break;
                    case 'boolean':
                        $column = new Column\BooleanColumn($c->getParams());
                        break;
                    case 'number':
                        $column = new Column\NumberColumn($c->getParams());
                        break;
                    case 'array':
                        $column = new Column\ArrayColumn($c->getParams());
                        break;
                    case 'text':
                    default:
                        $column = new Column\TextColumn($c->getParams());
                        break;
                }
            } else {
                $column = $c;
            }

            if (!$column->isPrimary()) {
                $column->setPrimary((is_array($this->id) && in_array($column->getId(), $this->id)) || $column->getId() == $this->id || $token);
            }

            $columns->addColumn($column);

            $token = false;
        }
    }

    /**
     * @param $columns \APY\DataGridBundle\Grid\Column\Column[]
     * @param $page int Page Number
     * @param $limit int Rows Per Page
     * @return \APY\DataGridBundle\Grid\Rows
     */
    public function execute($columns, $page = 0, $limit = 0, $maxResults = null)
    {
        return $this->executeFromData($columns, $page, $limit, $maxResults);
    }
    
     public function executeFromData($columns, $page = 0, $limit = 0, $maxResults = null) {
        $returnItems = array();
        $serializeColumns = array();
        
        //filter
        foreach($this->data as $key => $data) {
            $returnItems[$key] = $data;
            
            foreach($columns as $column) {
                if (!isset($data[$column->getField()])) {
                    continue;
                }
                $fieldValue = $data[$column->getField()];
                
                if ($column->isFiltered()) {
                    $filters = $column->getFilters('vector');
                    
                    foreach($filters as $filter) {
                        $operator = $filter->getOperator();
                        $value = $filter->getValue();

                        // Normalize value
                        switch ($operator) {
                            case Column\Column::OPERATOR_EQ:
                                $value = "/^$value$/i";
                                break;
                            case Column\Column::OPERATOR_NEQ:
                                $value = "/^(?!$value$).*$/i";
                                break;
                            case Column\Column::OPERATOR_LIKE:
                                $value = "/$value/i";;
                                break;
                            case Column\Column::OPERATOR_NLIKE:
                                $value = "/^((?!$value).)*$/i";
                                break;
                            case Column\Column::OPERATOR_LLIKE:
                                $value = "/$value$/i";
                                break;
                            case Column\Column::OPERATOR_RLIKE:
                                $value = "/^$value/i";
                                break;
                        }

                        // Test
                        switch ($operator) {
                            case Column\Column::OPERATOR_EQ:
                            case Column\Column::OPERATOR_NEQ:
                            case Column\Column::OPERATOR_LIKE:
                            case Column\Column::OPERATOR_NLIKE:
                            case Column\Column::OPERATOR_LLIKE:
                            case Column\Column::OPERATOR_RLIKE:
                                if ($column->getType() === 'array') {
                                    $fieldValue = str_replace(':{i:0;', ':{', serialize($fieldValue));
                                }

                                $found = preg_match($value, $fieldValue);
                                break;
                            case Column\Column::OPERATOR_GT:
                                $found = $fieldValue > $value;
                                break;
                            case Column\Column::OPERATOR_GTE:
                                $found = $fieldValue >= $value;
                                break;
                            case Column\Column::OPERATOR_LT:
                                $found = $fieldValue < $value;
                                break;
                            case Column\Column::OPERATOR_LTE:
                                $found = $fieldValue <= $value;
                                break;
                            case Column\Column::OPERATOR_ISNULL:
                                $found = $fieldValue === null;
                                break;
                            case Column\Column::OPERATOR_ISNOTNULL:
                                $found = $fieldValue !== null;
                                break;
                        }
                        
                        if (!$found) {
                            unset($returnItems[$key]);
                        }
                    }
                    if ($column->getType() === 'array') {
                        $serializeColumns[] = $column->getId();
                    }
                }
            }
        }
        
        //order
        foreach ($columns as $column) {
            if ($column->isSorted()) {
                $sortTypes = array();
                $sortedItems = array();
                foreach ($returnItems as $key => $item) {
                    $value = $item[$column->getField()];

                    // Format values for sorting and define the type of sort
                    switch ($column->getType()) {
                        case 'text':
                            $sortedItems[$key] = strtolower($value);
                            $sortType = SORT_STRING;
                            break;
                        case 'datetime':
                        case 'date':
                        case 'time':
                            if ($value instanceof \DateTime) {
                                $sortedItems[$key] = $value->getTimestamp();
                            } else {
                                $sortedItems[$key] = strtotime($value);
                            }
                            $sortType = SORT_NUMERIC;
                            break;
                        case 'boolean':
                            $sortedItems[$key] = $value ? 1 : 0;
                            $sortType = SORT_NUMERIC;
                            break;
                        case 'array':
                            $sortedItems[$key] = json_encode($value);
                            $sortType = SORT_STRING;
                            break;
                        case 'number':
                            $sortedItems[$key] = $value;
                            $sortType = SORT_NUMERIC;
                            break;
                        default:
                            $sortedItems[$key] = $value;
                            $sortType = SORT_REGULAR;
                    }
                }

                array_multisort($sortedItems, ($column->getOrder() == 'asc') ? SORT_ASC : SORT_DESC, $sortType, $returnItems);
                break;
            }
        }
        
        $this->count = count($returnItems);

        // Pagination
        if ($limit > 0) {
            $maxResults = ($maxResults !== null && ($maxResults - $page * $limit < $limit)) ? $maxResults - $page * $limit : $limit;

            $returnItems = array_slice($returnItems, $page * $limit, $maxResults);
        } elseif ($maxResults !== null) {
            $returnItems = array_slice($returnItems, 0, $maxResults);
        }

        $rows = new Rows();
        foreach ($returnItems as $item) {
            $row = new Row();

            if ($this instanceof Vector) {
                $row->setPrimaryField($this->id);
            }

            foreach ($item as $fieldName => $fieldValue) {
                if ($this instanceof Entity) {
                    if (in_array($fieldName, $serializeColumns)) {
                        if (is_string($fieldValue)) {
                            $fieldValue = unserialize($fieldValue);
                        }
                    }
                }

                $row->setField($fieldName, $fieldValue);
            }

            //call overridden prepareRow or associated closure
            if (($modifiedRow = $this->prepareRow($row)) != null) {
                $rows->addRow($modifiedRow);
            }
        }

        $this->items = $returnItems;

        return $rows;
    }

    public function populateSelectFilters($columns, $loop = false)
    {
        $this->populateSelectFiltersFromData($columns, $loop);
    }

    public function getTotalCount($maxResults = null)
    {
        return $this->getTotalCountFromData($maxResults);
    }

    public function getHash()
    {
        return __CLASS__.md5(implode('', array_map(function($c) { return $c->getId(); } , $this->columns)));
    }

    /**
     * sets the primary key
     * @param mixed $id either a string or an array of strings
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * Set a two-dimentional array
     * @param array $data
     * @throws \InvalidArgumentException
     */
    public function setData($data){
        $this->data = $data;

        if(!is_array($this->data) || empty($this->data)){
            throw new \InvalidArgumentException('Data should be an array with content');
        }

        if (is_object(reset($this->data))) {
            foreach ($this->data as $key => $object) {
                $this->data[$key] = (array) $object;
            }
        }

        $firstRaw = reset($this->data);
        if(!is_array($firstRaw) || empty($firstRaw)){
            throw new \InvalidArgumentException('Data should be a two-dimentional array');
        }
    }

    public function delete(array $ids){}

    protected function setColumns($columns)
    {
        $this->columns = $columns;
    }

    protected function hasColumn($id)
    {
        foreach ($this->columns as $c) {
            if ($id === $c->getId()) {
                return true;
            }
        }

        return false;
    }

    protected function getColumn($id)
    {
        foreach ($this->columns as $c) {
            if ($id === $c->getId()) {
                return $c;
            }
        }
    }
}
