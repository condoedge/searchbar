<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn;

enum OperatorEnum: int
{
    use \Kompo\Auth\Models\Traits\EnumKompo;

    // GENERAL
    case EQUALS_TO = 1;
    case DIFFERENT = 2;
    case IN = 3;
    case NOT_IN = 4;

    // TEXTS
    case CONTAINS = 10;
    case DOES_NOT_CONTAIN = 11;    

    // NUMBERS AND DATES
    case MORE_THAN = 20;
    case MORE_THAN_OR_EQUAL = 21;
    case LESS_THAN = 22;
    case LESS_THAN_OR_EQUAL = 23;
    case BETWEEN = 24;

    public function label()
    {
        return match ($this) {
            self::EQUALS_TO => 'filter.equals-to',
            self::DIFFERENT => 'filter.different',
            self::IN => 'filter.in',
            self::NOT_IN => 'filter.not-in',

            self::CONTAINS => 'filter.contains',
            self::DOES_NOT_CONTAIN => 'filter.does-not-contain',

            self::MORE_THAN => 'filter.more-than',
            self::MORE_THAN_OR_EQUAL => 'filter.more-than-or-equal',
            self::LESS_THAN => 'filter.less-than',
            self::LESS_THAN_OR_EQUAL => 'filter.less-than-or-equal',
            self::BETWEEN => 'filter.between',
        };
    }

    public function acceptFullTextSearch()
    {
        return match ($this) {
            self::CONTAINS => true,
            default => false,
        };
    }

    public function renderRule($rule)
    {
        return match ($this) {
            default => _Html(__($this->label() . '.with-value', [
                'value' => $rule->visualValue(),
            ])),
        };
    }

    public function operator()
    {
        return match ($this) {
            self::EQUALS_TO => '=',
            self::DIFFERENT => '!=',
            self::IN => 'in',
            self::NOT_IN => 'not in',

            self::CONTAINS => 'like',
            self::DOES_NOT_CONTAIN => 'not like',

            self::MORE_THAN => '>',
            self::MORE_THAN_OR_EQUAL => '>=',
            self::LESS_THAN => '<',
            self::LESS_THAN_OR_EQUAL => '<=',
            self::BETWEEN => 'between',
        };
    }


    // This method is used in relations where we need to negate the operator. because we use whereDoesntHave instead of whereHas
    public function negative()
    {
        return match ($this) {
            self::DIFFERENT => self::EQUALS_TO,
            self::NOT_IN => self::IN,
            self::DOES_NOT_CONTAIN => self::CONTAINS,

            default => null,
        };
    }

    public function constructQuery($query, $column, $val)
    {
        return match ($this) {
            self::BETWEEN => $query->whereBetween($column, collect($val)->sortKeys()->values()),
            self::IN => $query->whereIn($column, $val),
            self::NOT_IN => $query->whereNotIn($column, $val),

            default => $query->where($column, $this->operator(), $this->constructValue($val)),
        };
    }

    public function constructValue($val)
    {
        return match ($this) {
            self::CONTAINS => wildcardSpace($val),
            self::DOES_NOT_CONTAIN => wildcardSpace($val),

            default => $val,
        };
    }
}