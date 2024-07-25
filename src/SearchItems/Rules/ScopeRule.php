<?php

namespace Kompo\Searchbar\SearchItems\Rules;

class ScopeRule extends FilterableRule
{    
    protected $scope;
    protected $params;

    public function __construct($scope, ...$params)
    {
        $this->scope = $scope;
        $this->params = $params;
    }

    public function decorateQuery($query)
    {
        return $query->{$this->scope}(...$this->params);
    }

    public function toArray()
    {
        return [
            $this->scope,
            ...$this->params,
        ];
    }

    public function renderContent()
    {
        return _Html($this->getFilterable()->getName());
    }

    public function getValue()
    {
        return $this->scope;
    }

    public function setValue($value)
    {
        $this->scope = $value;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function setParams($params)
    {
        $this->params = $params;
    }
}