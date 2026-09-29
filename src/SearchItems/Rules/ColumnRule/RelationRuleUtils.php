<?php

namespace Kompo\Searchbar\SearchItems\Rules\ColumnRule;

use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;

trait RelationRuleUtils
{
    public function decorateQuery($query)
    {
        if ($this->isPendingValue()) {
            return $query;
        }

        $columnInfo = explode('.', $this->getRawColumn());

        return $this->applyRelationQuery($query, $columnInfo, $query->getModel(), true);
    }

    /**
     * When we had a "not in" operator, we didn't receive the result if the relation was empty. So, i changed to "whereDoesntHave" in negative operator.
     */
    protected function applyRelationQuery($query, $relations, $latestRelationClass, $first = false) {
        if(count($relations) == 1) {
            $relationTable = $latestRelationClass->getTable();

            // Positive inside whereDoesntHave, but a column without relation segment has no whereDoesntHave: it
            // keeps its own operator (NOT IN became IN).
            $operator = !$first && $this->operator->negative() != null ? $this->operator->negative() : $this->operator;
            $column = $this->isRawColumn() ? \DB::raw($relations[0]) : $relationTable . '.' .$relations[0];

            // Full text on the related column: MATCH inside the EXISTS, every word required. "Does not contain" gets
            // here as CONTAINS inside its whereDoesntHave.
            if ($operator === OperatorEnum::CONTAINS && !$this->isRawColumn() && $this->relationUsesFullText($query, $relationTable, $relations[0])) {
                return $this->fullTextFilterQuery($query, $column);
            }

            if ($digits = $this->searchedDigits($operator)) {
                return $this->digitsQuery($query, $column, $operator, $digits);
            }

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

    /**
     * The filterable asks for full text (FilterableColumn::fullTextSearch()), else LIKE: unresolved (a rule built
     * alone), a value without a word (a symbol, "#": MATCH had no condition, so "contains" kept everyone with an
     * address and "does not contain" only people without one), or no FULLTEXT index on the column yet (MATCH failed
     * with 1191: the host's migration can run after the code is deployed).
     */
    protected function relationUsesFullText($query, string $table, string $column): bool
    {
        $filterable = $this->resolvedFilterable();

        return $filterable && method_exists($filterable, 'hasFullTextSearch') && $filterable->hasFullTextSearch()
            && $this->fullTextWords($this->value)
            && static::hasFullTextIndex($this->fullTextBaseBuilder($query)->getConnection(), $table, [$column]);
    }
}
