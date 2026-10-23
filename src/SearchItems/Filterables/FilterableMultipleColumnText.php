<?php

namespace Kompo\Searchbar\SearchItems\Filterables;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumnTypeEnum;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\Components\RuleForm\MultipleColumnTextRuleForm;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Rules\MultipleColumnTextRule;
use Kompo\Searchbar\SearchItems\Rules\UnusableRuleData;

class FilterableMultipleColumnText extends Filterable
{
    use AcceptFullTextSearch;

    protected $availableMethods = [
        'setColumns',
        'setRuleOperator',
    ];

    protected array $columnsOptions;
    protected $form = MultipleColumnTextRuleForm::class;

    public function __construct(array $columnsOptions)
    {
        $this->columnsOptions = $columnsOptions;
    }

    /** Fields named by rule id: see FilterableColumn::formRow(). */
    public function formRow($rule, $ruleId): array
    {
        return [
            $this->getSelectColumnOptions()->class('!mb-0')
                ->name('columns_' . $ruleId)->default($rule->getColumns())
                ->onChange(fn($e) => $e->post('searchstate.execute-custom-filterable-function', $this->ruleRowParams($ruleId, ['function' => 'setColumns']))->withAllFormValues()->refresh($this->getContext()->refreshTargets()))
                ->overModal('columns' . \Str::random(5) . time())
                ->class('!mb-0')
                ->col('col-md-3'),

            _Select()->class('!mb-0')->options(FilterableColumnTypeEnum::TEXT->getOperatorOptionsParsed())
                ->name('operator_' . $ruleId)->default($rule->getOperator())
                ->onChange(fn($e) => $e
                    ->post('searchstate.execute-custom-filterable-function', $this->ruleRowParams($ruleId, ['function' => 'setRuleOperator']))->withAllFormValues()->refresh($this->getContext()->refreshTargets())
                )
                ->overModal('operator' . \Str::random(5) . time())
                ->class('!mb-0')
                ->col('!p-0 col-md-3'),

            // On change (blur), not on every keystroke with a full navbar refresh each time.
            _Input()->name('value_' . $ruleId)
                ->onChange(fn($e) => $e->post('searchstate.set-rule-value', $this->ruleRowParams($ruleId))->withAllFormValues()->refresh($this->getContext()->refreshTargets()))
                ->class('!mb-0')
                ->value($rule->getValue())
                ->col('col-md-6'),
        ];
    }

    protected function setRuleOperator($ruleId)
    {
        $posted = static::postedRuleField('operator_', $ruleId);
        $operator = is_scalar($posted) ? OperatorEnum::tryFrom((int) $posted) : null;

        if (!in_array($operator, FilterableColumnTypeEnum::TEXT->operatorOptions(), true)) {
            return;
        }

        $this->updateRule($ruleId, function($rule) use ($operator) {
            $rule->setOperator($operator);

            return $rule;
        });
    }

    /** Only declared columns: they are written into the SQL (MATCH(...) with full-text search). */
    public function setColumns($ruleId)
    {
        $columns = collect((array) static::postedRuleField('columns_', $ruleId))
            ->filter(fn($column) => is_string($column) && array_key_exists($column, $this->columnsOptions))
            ->values()->all();

        if (!$columns) {
            return;
        }

        $this->updateRule($ruleId, function($rule) use ($columns) {
            $rule->setColumns($columns);

            return $rule;
        });
    }

    // INLINE EDITION (rule pills): the searched text
    public function supportsInlineEdit(): bool
    {
        return true;
    }

    public function getInlineEditor($name, $value = null, $operator = null, $onApply = null)
    {
        return FilterableColumnTypeEnum::TEXT->inlineInput($name, $onApply, is_array($value) ? null : $value);
    }

    public function normalizeInlineValue($value, $operator = null)
    {
        return FilterableColumnTypeEnum::TEXT->normalizeValue($value, OperatorEnum::EQUALS_TO);
    }

    // GETTERS
    public function getColumnsOptions()
    {
        return $this->columnsOptions;
    }

    public function getSelectColumnOptions()
    {
        return _MultiSelect()->options(collect($this->columnsOptions)->mapWithKeys(fn($column, $i) => [$i => __($column)])->toArray());
    }

    public function getRuleInstance($params)
    {
        // Columns come from the add-rule form: only declared ones, they end up in the SQL.
        $columns = collect($params['columns'] ?? [])->filter(fn($column) => is_string($column) && array_key_exists($column, $this->columnsOptions))->values()->all();

        return new MultipleColumnTextRule($columns, $params['operator'], $params['value'] ?? null);
    }

    /**
     * A text operator (none stored: the default), the stored columns still declared (none left: unusable, it would
     * search nothing), the value as text (a list of words stored by older forms stays one: searched joined).
     */
    public function ruleFromData(array $data): FilterableRule
    {
        $operator = static::storedOperator($data) ?? FilterableColumnTypeEnum::TEXT->defaultOperator();

        if (!in_array($operator, FilterableColumnTypeEnum::TEXT->operatorOptions(), true)) {
            throw new UnusableRuleData('invalid_operator', $operator->name);
        }

        $value = $data['value'] ?? null;
        $value = is_array($value)
            ? (collect($value)->flatten()->filter(fn($v) => is_scalar($v) && trim((string) $v) !== '')->map(fn($v) => trim((string) $v))->values()->all() ?: null)
            : $this->normalizeInlineValue(is_scalar($value) ? (string) $value : null);

        $rule = $this->getRuleInstance(['columns' => is_array($data['columns'] ?? null) ? $data['columns'] : [], 'operator' => $operator, 'value' => $value]);

        if (!$rule->getColumns()) {
            throw new UnusableRuleData('invalid_columns', UnusableRuleData::describe($data['columns'] ?? null));
        }

        return $rule;
    }
}