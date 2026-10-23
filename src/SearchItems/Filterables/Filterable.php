<?php

namespace Kompo\Searchbar\SearchItems\Filterables;

use Kompo\Searchbar\Components\RuleForm\AbstractRuleForm;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Rules\UnusableRuleData;
use Kompo\Searchbar\SearchItems\SearchItem;

abstract class Filterable extends SearchItem
{
    protected $name;
    protected $assignedRule;
    protected $form;
    protected ?string $filterKey = null;

    protected $availableMethods = [];

    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /** Its key in the searchable's filterables() (set by decoratedFilterables()): selects that search on the server name it. */
    public function setFilterKey(?string $key): static
    {
        $this->filterKey = $key;

        return $this;
    }

    public function getFilterKey(): ?string
    {
        return $this->filterKey ?? $this->assignedRule?->getKeyReference();
    }

    /**
     * Used to get the name of column or scope
     */
    public function getName()
    {
        return $this->name ?? '';
    }

    /**
     * Used to get the name showed on CustomFiltersModal (type of filter)
     * @return void
     */
    public function getFilterName()
    {
        return $this->getName();
    }

    /** $ruleId: the id of the rule of the modal row (SearchState::findRule()). */
    public function executeCustomMethod($function, $ruleId)
    {
        // Strict: a crafted true passed the loose check and failed on the call.
        if (!is_string($function) || !in_array($function, $this->availableMethods, true)) {
            return;
        }

        return $this->$function($ruleId);
    }

    /**
     * On this filterable's own search service: the default one was used, whichever state the request was for. A
     * read-modify-write of the state as it is now (SearchStore::mutate()): the row's operator select and value input
     * post side by side, and a slower read no longer puts the old operator back. $callback may run twice.
     */
    protected function updateRule($ruleId, $callback)
    {
        $this->getContext()->getStore()->mutate(function ($state) use ($ruleId, $callback) {
            $rule = $state->findRule($ruleId);

            if (!$rule) {
                return false;
            }

            $state->replaceRule($ruleId, $callback($rule));
        });
    }

    /**
     * A field of a rule's editor (custom filters modal row, pill editor): named by the rule's id (value_{id}); on a
     * page rendered before ids, by the index it posted (?i); else unsuffixed (a single-rule form). Every row sits in
     * one form: with shared names each request carried the LAST row's value.
     */
    public static function postedRuleField(string $prefix, ?string $ruleId)
    {
        foreach ([$ruleId, is_numeric(request('i')) ? (string) request('i') : null] as $suffix) {
            if ($suffix !== null && $suffix !== '' && request()->has($prefix . $suffix)) {
                return request($prefix . $suffix);
            }
        }

        return request(rtrim($prefix, '_'));
    }

    /**
     * Route params of a custom filters modal row's requests: its rule (id, and filter key: a request whose id names
     * another filter changes nothing) and its state, in the URL, whichever form posts them.
     */
    protected function ruleRowParams($ruleId, array $params = []): array
    {
        $params = array_merge(['ruleId' => $ruleId, 'key' => $this->getFilterKey()], $params);

        return $this->hasContext() ? $this->getContext()->stateParams($params) : $params;
    }

    public function setAssignedRule($rule)
    {
        $this->assignedRule = $rule;

        return $this;
    }

    public function getAssignedRule()
    {
        return $this->assignedRule;
    }

    /**
     * The rule form of this filter ("New filter"), on the given state. $serviceKey / $refreshId: when it edits a
     * table's state (custom filters modal opened by a table) instead of the navbar's.
     */
    public final function form($key, $storeKey, $serviceKey = null, $refreshId = null): AbstractRuleForm
    {
        if(!$this->form || !class_exists($this->form) || !is_subclass_of($this->form, AbstractRuleForm::class)) {
            throw new \Exception('Form not found or not a subclass of AbstractRuleForm. Please set the form property "form" in your Filterable class.');
        }

        return new ($this->form)(array_filter([
            'key' => $key,
            'storeKey' => $storeKey,
            'serviceKey' => $serviceKey,
            'refresh_id' => $refreshId,
        ], fn($prop) => $prop !== null));
    }

    abstract public function getRuleInstance($params);

    /**
     * This filter's rule from stored data (StateCodec, format v2: 'op', 'value' and the rule's own keys, see
     * FilterableRule::toData()), built as the filter is declared now: a changed column or rule class applies to states
     * stored before. Throws UnusableRuleData (its reason) when the data can't be one of its rules: the rule is dropped
     * from the state and logged. The key, id and editor flag are set by the caller.
     *
     * A host filterable built on Filterable gets the stored operator and value as its rule form posts them (and the
     * rule's other data keys); override it to check them.
     */
    public function ruleFromData(array $data): FilterableRule
    {
        $params = array_diff_key($data, array_flip(['id', 't', 'key', 'op', 'editing']));
        $operator = static::storedOperator($data);

        $rule = $this->getRuleInstance(($operator ? ['operator' => $operator] : []) + $params + ['value' => null]);

        if (!$rule instanceof FilterableRule) {
            throw new UnusableRuleData('not_a_filter');
        }

        return $rule;
    }

    /** The stored operator ('op': its int value), null when none is stored. Not an operator: UnusableRuleData. */
    protected static function storedOperator(array $data): ?OperatorEnum
    {
        if (($data['op'] ?? null) === null) {
            return null;
        }

        $operator = is_int($data['op']) || (is_string($data['op']) && ctype_digit($data['op'])) ? OperatorEnum::tryFrom((int) $data['op']) : null;

        return $operator ?? throw new UnusableRuleData('invalid_operator', UnusableRuleData::describe($data['op']));
    }

    /** A row of the custom filters modal for $rule: its fields are named, and its requests address it, by $ruleId. */
    abstract public function formRow($rule, $ruleId): array;

    public function defaultValueParsed($val)
    {
        return $val;
    }

    // INLINE EDITION (rule pills). Off by default: a filterable opts in by overriding these.
    public function supportsInlineEdit(): bool
    {
        return false;
    }

    /** Whether Enter applies the pill editor (not when Enter already means something in the input, like a select). */
    public function inlineEnterApplies(): bool
    {
        return true;
    }

    /**
     * The input of the pill editor, named $name and prefilled with $value.
     * @param \Closure|null $onApply for inputs that apply on change (date pickers): the save interaction
     */
    public function getInlineEditor($name, $value = null, $operator = null, $onApply = null)
    {
        return null;
    }

    /** What the pill editor posted, cleaned. Null means no value: the filter is removed. */
    public function normalizeInlineValue($value, $operator = null)
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === '' || $value === [] || $value === null) ? null : $value;
    }

    /** The text typed in the searchbar as this filter's rule params, or null when it can't be one. */
    public function valueFromSearchText(string $text): ?array
    {
        return null;
    }
}
