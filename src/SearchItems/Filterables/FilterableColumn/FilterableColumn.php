<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn;

use Kompo\Searchbar\SearchItems\Filterables\AcceptFullTextSearch;
use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType\EntityType;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Rules\UnusableRuleData;
use Kompo\Searchbar\Components\RuleForm\ColumnRuleForm;

class FilterableColumn extends Filterable
{
    use AcceptFullTextSearch;

    protected $availableMethods = [
        'setRuleOperator',
        'setValueInput',
    ];

    protected string $column;
    protected FilterableColumnTypeEnum $inputType;
    protected ?EntityType $entityType;
    protected $form = ColumnRuleForm::class;

    // Flags, not closures: the filterables are rebuilt from the searchable for every rule (saved ones included).
    protected bool $digitsOnly = false;
    protected ?string $digitsCountryCode = null;
    protected int $digitsNationalLength = 10;

    public function __construct(string $column, FilterableColumnTypeEnum $inputType, ?EntityType $entityType = null)
    {
        $this->column = $column;
        $this->inputType = $inputType;
        $this->entityType = $entityType;
    }

    /**
     * Text operators (contains, equals and their negations) compare digits only, on both sides: "(514) 555-1234",
     * "514.555.1234" and "5145551234" find the same number, however it is stored. A typed value without any digit is
     * compared as typed. With $countryCode, a typed number made of that code and a national number ($nationalLength
     * digits) is that national number too, and the reverse: "+1 514 555-1234" (as SISC displays numbers) finds the
     * numbers stored as "5145551234", "equals 5145551234" finds "+15145551234".
     */
    public function searchDigitsOnly(?string $countryCode = null, int $nationalLength = 10): static
    {
        $this->digitsOnly = true;
        $this->digitsCountryCode = $countryCode === null ? null : (preg_replace('/\D+/', '', $countryCode) ?: null);
        $this->digitsNationalLength = $nationalLength;

        return $this;
    }

    public function searchesDigitsOnly(): bool
    {
        return $this->digitsOnly;
    }

    /**
     * The digit strings a typed value stands for (searchDigitsOnly()): its digits, then the same number with or
     * without the country code. [] when it has no digit.
     */
    public function searchDigits($value): array
    {
        $digits = preg_replace('/\D+/', '', is_scalar($value) ? (string) $value : '');

        if ($digits === '') {
            return [];
        }

        $code = $this->digitsCountryCode;
        $national = $this->digitsNationalLength;

        return array_values(array_unique(array_filter([
            $digits,
            $code !== null && strlen($digits) === strlen($code) + $national && str_starts_with($digits, $code) ? substr($digits, strlen($code)) : null,
            $code !== null && strlen($digits) === $national ? $code . $digits : null,
        ])));
    }

    /**
     * A row of the custom filters modal. Its fields are named by its rule's id (operator_{id}, value_{id}): every row
     * sits in the same form, so with shared names each request carried the LAST row's operator/value (arrays reached
     * text rules this way). Its requests name the rule and its state (ruleRowParams()).
     */
    public function formRow($rule, $ruleId): array
    {
        return [
            _Html($this->getFilterName())->class('text-sm font-semibold text-greenmain')->col('!pr-0 col-md-3'),
            _Select()->class('!mb-0')->options($this->getInputType()->getOperatorOptionsParsed())
            ->name('operator_' . $ruleId)->default($rule->getOperator())
            ->onChange(fn($e) => $e->post('searchstate.execute-custom-filterable-function', $this->ruleRowParams($ruleId, ['function' => 'setRuleOperator']))->withAllFormValues()->refresh($this->getContext()->refreshTargets()) &&
                // The new input is rendered by that request: it is told what to refresh (the modal's table; none: the
                // navbar's parts).
                $e->post('searchstate.execute-custom-filterable-function', $this->ruleRowParams($ruleId, array_filter(['function' => 'setValueInput', 'refresh' => $this->getContext()->refreshTargetId()])))->withAllFormValues()
                ->inPanel('input-panel-' . $ruleId)
            )
            ->overModal('operator' . \Str::random(5) . time())
            ->class('!mb-0')->col('!p-0 col-md-3'),

            _Panel(
                $this->valueInput($rule->getOperator(), $ruleId, $rule->getValue()),
            )->id('input-panel-' . $ruleId)->col('col-md-6'),
        ];
    }

    protected function valueInput($operator, $ruleId, $value = null)
    {
        $value = $this->getInputType()->normalizeValue($value, $operator);

        return $this->getInput($operator, $value)->name('value_' . $ruleId)
            ->onChange(fn($e) => $e->post('searchstate.set-rule-value', $this->ruleRowParams($ruleId))->withAllFormValues()->refresh($this->getContext()->refreshTargets()))
            ->class('!mb-0')
            ->when($value !== null, fn($el) => $el->value($value));
    }

    /** The value is reshaped for the new operator (a scalar under BETWEEN failed the query with HY093). */
    protected function setRuleOperator($ruleId)
    {
        $operator = $this->requestedOperator($ruleId);

        if (!$operator) {
            return;
        }

        $this->updateRule($ruleId, function($rule) use ($operator) {
            $rule->setOperator($operator);
            $rule->setValue($this->getInputType()->normalizeValue($rule->getValue(), $operator));

            return $rule;
        });
    }

    /** Runs next to setRuleOperator (same change event): reshapes the stored value itself instead of reading its result. */
    protected function setValueInput($ruleId)
    {
        $rule = $this->getContext()->getStore()->getState()->findRule($ruleId);
        $operator = $this->requestedOperator($ruleId);

        if (!$rule || !$operator) {
            return null;
        }

        return $this->valueInput($operator, $ruleId, $rule->getValue());
    }

    protected function requestedOperator($ruleId): ?OperatorEnum
    {
        // Scalars only: a crafted list cast to 1 (EQUALS_TO).
        $posted = static::postedRuleField('operator_', $ruleId);
        $operator = is_scalar($posted) ? OperatorEnum::tryFrom((int) $posted) : null;

        return in_array($operator, $this->getInputType()->operatorOptions(), true) ? $operator : null;
    }

    /** The "all" option stands for every option: their ids (not their labels, which matched nothing). */
    public function defaultValueParsed($val)
    {
        $entityType = $this->getEntityType();

        if($entityType && $entityType->hasAllowAllOption() && is_array($val) && in_array('all', $val)) {
            return collect($entityType->optionsWithLabels())->keys()->reject(fn($key) => $key === 'all')->values()->all();
        }

        return $val;
    }

    // INLINE EDITION (rule pills)
    public function supportsInlineEdit(): bool
    {
        return true;
    }

    /** Enter picks an option in selects and confirms a typed date in pickers: it only applies on the others. */
    public function inlineEnterApplies(): bool
    {
        return !in_array($this->getInputType(), [
            FilterableColumnTypeEnum::DATE,
            FilterableColumnTypeEnum::ENUM,
            FilterableColumnTypeEnum::SELECT,
            FilterableColumnTypeEnum::RELATION_SELECT,
        ], true);
    }

    public function getInlineEditor($name, $value = null, $operator = null, $onApply = null)
    {
        [$options, $serverSearch] = $this->selectOptions($value);

        return $this->getInputType()->inlineInput($name, $onApply, $value, $options, $operator, $serverSearch);
    }

    public function normalizeInlineValue($value, $operator = null)
    {
        return $this->getInputType()->normalizeValue($value, $operator);
    }

    public function valueFromSearchText(string $text): ?array
    {
        return $this->getInputType()->parseSearchText($text, fn() => $this->getEntityType()?->optionsWithLabels() ?: []);
    }

    // GETTERS
    public function getColumn()
    {
        return $this->column;
    }

    public function getInputType()
    {
        return $this->inputType;
    }

    public function getEntityType()
    {
        return $this->entityType?->injectContext($this->searchContextService);
    }

    public function getRuleInstance($params)
    {
        return $this->getInputType()->getRuleInstance(array_merge([
            'column' => $this->getColumn(),
            'operator' => $this->getInputType()->defaultOperator(),
        ], $params));
    }

    /**
     * On the column and rule class declared now (a stored pill kept the column it was saved with). One of the field's
     * operators (none stored: its default), the value in that operator's shape; no value is a pending pill.
     */
    public function ruleFromData(array $data): FilterableRule
    {
        $type = $this->getInputType();
        $operator = static::storedOperator($data) ?? $type->defaultOperator();

        if (!in_array($operator, $type->operatorOptions(), true)) {
            throw new UnusableRuleData('invalid_operator', $operator->name);
        }

        return $this->getRuleInstance(['operator' => $operator, 'value' => $type->normalizeValue($data['value'] ?? null, $operator)]);
    }

    /** @param mixed $value the current value: capped option lists (relations) still include it */
    public function getInput($operator = null, $value = null)
    {
        [$options, $serverSearch] = $this->selectOptions($value);

        return $this->getInputType()?->input($options, $operator ?? $this->getInputType()->defaultOperator(), $serverSearch)->name('value');
    }

    /**
     * [options, ajaxPayload|null] of this filter's select. A relation over searchbar.relation-search-threshold records
     * (that can be searched) sends only its selected values, labelled; the others come from
     * SearchKomponentUtils::searchbarRelationOptions() as the user types. The payload names the filter: Kompo's
     * option search sends nothing else about the select (no field name, no form values).
     */
    protected function selectOptions($value): array
    {
        $entityType = $this->getEntityType();

        if (!$entityType) {
            return [[], null];
        }

        $selected = is_array($value) ? $value : ($value === null ? [] : [$value]);
        $key = $this->getFilterKey();

        if ($key !== null && $this->getInputType()->offersOptions() && $entityType->searchesOnServer()) {
            return [$entityType->selectedOptions($selected), ['searchbar_filter' => $key]];
        }

        return [$entityType->optionsForInput($selected) ?: [], null];
    }

    /**
     * @deprecated use getInlineEditor(). No longer prefilled with the navbar search: a "Search by" chip applies
     * that text itself (SearchStateController::columnChip), and it was wrong content for date/number editors.
     */
    public function getInlineInput($name, $onEnter = null, $defaultValue = null)
    {
        return $this->getInlineEditor($name, $defaultValue, null, $onEnter);
    }
}