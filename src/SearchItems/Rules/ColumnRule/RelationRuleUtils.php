<?php

namespace Kompo\Searchbar\SearchItems\Rules\ColumnRule;

trait RelationRuleUtils
{    
    public function decorateQuery($query)
    {
        $columnInfo = explode('.', $this->getRawColumn());

        return $this->applyRelationQuery($query, $columnInfo, $query->getModel(), true);
    }

    /**
     * When we had a "not in" operator, we didn't receive the result if the relation was empty. So, i changed to "whereDoesntHave" in negative operator.
     */
    protected function applyRelationQuery($query, $relations, $latestRelationClass, $first = false) {
        if(count($relations) == 1) {
            $relationTable = $latestRelationClass->getTable();

            $operator = $this->operator->negative() != null ? $this->operator->negative() : $this->operator;
            $column = $this->isRawColumn() ? \DB::raw($relations[0]) : $relationTable . '.' .$relations[0];

            return $operator->constructQuery($query, $column, $this->queryValue());
        } 

        $relation = array_shift($relations);

        $method = ($first && $this->operator->negative() != null) ? 
            'whereDoesntHave' : 'whereHas';

        return $query->$method($relation, function ($query) use ($relations, $relation, $latestRelationClass) {
            $latestRelation = $latestRelationClass->$relation()->getRelated();

            return $this->applyRelationQuery($query, $relations, $latestRelation);
        });
    }

    public function queryValue()
    {
        return $this->operator->constructValue($this->value, $this);
    }
}