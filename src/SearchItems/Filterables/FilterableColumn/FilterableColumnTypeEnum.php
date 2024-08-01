<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn;

use Kompo\Searchbar\SearchItems\Rules\ColumnRule\ColumnRule;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\DateRule;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\EnumRule;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\InputRelationRule;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\SelectRelationRule;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\SelectRule;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;

enum FilterableColumnTypeEnum: int
{
    case TEXT = 1;
    case ENUM = 2;
    case DATE = 3;
    case NUMBER = 4;
    case NUMBER_CURRENCY = 5;
    case SELECT = 6;
    case RELATION_SELECT = 7;
    case RELATION_INPUT = 8;

    public function operatorOptions()
    {
        return match ($this) {
            self::TEXT => [
                OperatorEnum::EQUALS_TO,
                OperatorEnum::DIFFERENT,

                OperatorEnum::CONTAINS,
                OperatorEnum::DOES_NOT_CONTAIN,
            ],
            self::ENUM => [
                OperatorEnum::IN,
                OperatorEnum::NOT_IN,
            ],
            self::RELATION_SELECT => [
                OperatorEnum::IN,
                OperatorEnum::NOT_IN,
            ],
            self::RELATION_INPUT => [
                OperatorEnum::EQUALS_TO,
                OperatorEnum::DIFFERENT,

                OperatorEnum::CONTAINS,
                OperatorEnum::DOES_NOT_CONTAIN,
            ],
            self::DATE => [
                OperatorEnum::EQUALS_TO,
                OperatorEnum::DIFFERENT,
                OperatorEnum::MORE_THAN,
                OperatorEnum::MORE_THAN_OR_EQUAL,
                OperatorEnum::LESS_THAN,
                OperatorEnum::LESS_THAN_OR_EQUAL,
                OperatorEnum::BETWEEN,
            ],
            self::NUMBER, self::NUMBER_CURRENCY => [
                OperatorEnum::EQUALS_TO,
                OperatorEnum::DIFFERENT,
                OperatorEnum::MORE_THAN,
                OperatorEnum::MORE_THAN_OR_EQUAL,
                OperatorEnum::LESS_THAN,
                OperatorEnum::LESS_THAN_OR_EQUAL,
                OperatorEnum::BETWEEN,
            ],
            self::SELECT => [
                OperatorEnum::IN,
                OperatorEnum::NOT_IN,
            ],
        };
    }

    public function getOperatorOptionsParsed()
    {
        return collect($this->operatorOptions())->mapWithKeys(
            fn($option) => [$option->value => __($option->label())]
        )->toArray();
    }

    public function defaultOperator()
    {
        return match ($this) {
            self::TEXT => OperatorEnum::CONTAINS,
            self::ENUM => OperatorEnum::IN,
            self::DATE => OperatorEnum::EQUALS_TO,
            self::NUMBER, self::NUMBER_CURRENCY => OperatorEnum::BETWEEN,
            self::RELATION_SELECT => OperatorEnum::IN,
            self::RELATION_INPUT => OperatorEnum::CONTAINS,
            self::SELECT => OperatorEnum::IN,
        };
    }

    public function rule(): string
    {
        return match ($this) {
            self::ENUM => EnumRule::class,
            self::RELATION_SELECT => SelectRelationRule::class,
            self::RELATION_INPUT => InputRelationRule::class,

            self::DATE => DateRule::class,
            self::SELECT => SelectRule::class,
            
            default => ColumnRule::class,
        };
    }

    public function getRuleInstance($params): FilterableRule
    {
        $rule = $this->rule();

        return match($this) {
            self::SELECT, self::RELATION_SELECT, self::ENUM => new $rule($params['column'], $params['operator'], is_array($params['value']) ? $params['value'] : [$params['value']]),

            default => new $rule($params['column'], $params['operator'], $params['value']),
        };
    }

    public function input($params = [], ?OperatorEnum $operator = null)
    {
        return match ($this) {
            self::TEXT => _Input(),
            self::ENUM, self::RELATION_SELECT, self::SELECT => match ($operator) {
                OperatorEnum::IN, OperatorEnum::NOT_IN => _MultiSelect()->options($params),
                default => _Select()->options($params),
            },
            self::DATE => match ($operator) {
                OperatorEnum::BETWEEN => _DateRange(),
                default => _Date(),
            },
            self::NUMBER => match ($operator) {
                OperatorEnum::BETWEEN => _NumberRange(),
                default => _InputNumber(),
            },
            self::NUMBER_CURRENCY => match ($operator) {
                OperatorEnum::BETWEEN => _NumberRange()->rIcon('<span>$</span>')->inputClass('input-number-no-arrows text-right'),
                default => _InputDollar(),
            },
            self::RELATION_INPUT => _Input(),
        };
    }
}