<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn;

use Kompo\Searchbar\SearchItems\Rules\ColumnRule\ColumnRule;

enum OperatorEnum: int
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;

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
            // Escaped: _Html renders with v-html, and the value is user text (or a DB label).
            default => _Html(__($this->label() . '.with-value', [
                'value' => e((string) $rule->visualValue()),
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
            self::BETWEEN => $this->betweenQuery($query, $column, $val),
            self::IN => $query->whereIn($column, (array) $val),
            self::NOT_IN => $query->whereNotIn($column, (array) $val),

            default => $query->where($column, $this->operator(), $this->constructValue($val)),
        };
    }

    /**
     * [5, null] is ">= 5" and [null, 10] "<= 10" (whereBetween with a null bound matched nothing), and reversed
     * bounds are swapped (sortKeys() only reordered the keys, never the values).
     */
    protected function betweenQuery($query, $column, $val)
    {
        [$from, $to] = array_values(array_map(
            fn($v) => is_string($v) && trim($v) === '' ? null : $v,
            is_array($val) ? array_values($val) : [$val, $val],
        )) + [null, null];

        return match (true) {
            $from !== null && $to !== null => $query->whereBetween($column, $from <= $to ? [$from, $to] : [$to, $from]),
            $from !== null => $query->where($column, '>=', $from),
            $to !== null => $query->where($column, '<=', $to),
            default => $query,
        };
    }

    public function constructValue($val, ColumnRule $rule = null)
    {
        // Wildcard operators take text. Arrays used to reach them when the custom filters modal rows shared one
        // "value" field name (the last row won); the modal names its fields per row now, and pill editors normalize
        // values per operator. Joining keeps any stored leftover from breaking the query.
        if (is_array($val) && ($this === self::CONTAINS || $this === self::DOES_NOT_CONTAIN)) {
            $val = collect($val)->flatten()->filter(fn($v) => is_scalar($v) && $v !== '')->implode(' ');
        }

        return match ($this) {
            self::CONTAINS => wildcardSpace($val),
            self::DOES_NOT_CONTAIN => wildcardSpace($val),

            default => $rule?->getFilterable()?->defaultValueParsed($val) ?? $val,
        };
    }

    /** How a value is shown in a pill: "5 – 10", "≥ 5", "≤ 10" for ranges. */
    public function visualValue($value)
    {
        if ($this === self::BETWEEN && is_array($value)) {
            [$from, $to] = array_values($value) + [null, null];

            return match (true) {
                $from !== null && $to !== null => $from . ' – ' . $to,
                $from !== null => '≥ ' . $from,
                $to !== null => '≤ ' . $to,
                default => '',
            };
        }

        return is_array($value) ? implode(', ', array_filter($value, fn($v) => $v !== null && $v !== '')) : $value;
    }
}