<?php

namespace Kompo\Searchbar\SearchItems\Rules;

use Kompo\Searchbar\SearchItems\Rules\Rule;
use Kompo\Searchbar\Searchable\Searchable;

/**
 * I want to keep the rule base model separeted from the searchable,
 * so i created a new abstract class FilterableRule, to give it the implementation of searchable filterables.
 */
abstract class FilterableRule extends Rule
{
    protected $searchable;
    protected $keyReference;
    protected $rule;
    protected bool $editing = false;

    public function getFilterable(Searchable $searchable = null)
    {
        $searchable = $searchable ?? $this->searchable ?? $this->getState()->getSearchableInstanceForResultsPanel();

        if (!$searchable) {
            return null;
        }

        // Null for a filter the searchable no longer declares: its pill still renders (and can be removed).
        return $searchable->filterable($this->keyReference)?->setAssignedRule($this);
    }

    public function setKeyReference($keyReference)
    {
        $this->keyReference = $keyReference;

        return $this;
    }

    public function getKeyReference()
    {
        return $this->keyReference;
    }

    public function setSearchable($searchable)
    {
        $this->searchable = $searchable;

        if (is_string($this->searchable)) {
            $this->searchable = $this->searchable::createWithContext($this->searchContextService);
        }

        return $this;
    }

    /**
     * The pill of this rule, wherever it is shown (navbar, tables, results page). Its actions address the rule by its
     * id in its own search state (serviceKey/storeKey from its context) and refresh the komponent that renders it.
     * $index: unused (kept for callers passing it).
     */
    public function render($index = null, $withDeleteButton = true)
    {
        if ($this->isPendingValue() || $this->isEditing()) {
            return $this->renderInline();
        }

        $filterable = $this->getFilterable();
        $content = $this->renderContent();
        $fullValue = method_exists($this, 'visualValue') ? $this->visualValue() : null;

        // Long values (several options) pushed the search input out of view: truncated, full value on hover.
        if ($filterable?->supportsInlineEdit()) {
            // A Link (inline spinner) rather than a clickable Flex, which showed kompo's full-screen spinner. The text
            // is truncated in its own span: on the flex link itself the ellipsis never showed and hid the edit icon.
            $contentEl = $this->stateAction(
                $content instanceof \Kompo\Html
                    ? _Link('<span class="truncate min-w-0">' . $content->label . '</span><span class="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity inline-flex ml-1">' . _SaxSvg('edit-2', 12) . '</span>')
                        ->class('group inline-flex items-center max-w-[16rem]')
                    : _Flex($content)->class('cursor-pointer truncate max-w-[16rem]'),
                'searchstate.make-rule-editable',
            );
        } else {
            $contentEl = $content->class('truncate max-w-[16rem]');
        }

        $contentEl->when(is_scalar($fullValue) && $fullValue !== '', fn($el) => $el->title(strip_tags((string) $fullValue)));

        return _RulePill(
            $filterable?->getFilterName(),
            _Flex(
                $contentEl,
                !$withDeleteButton ? null : $this->stateAction(
                    _Link()->icon('x')->class('opacity-60 hover:opacity-100 hover:text-danger')->title('filter.remove'),
                    'searchstate.delete-rule',
                ),
            )->class('gap-2 items-center'),
        );
    }

    /**
     * The editor of a pending pill (just added, no value yet) or of a pill being edited.
     * ✓ applies the posted value (empty removes the filter); ✕ cancels: the pill keeps its value, or is removed
     * if it never had one. The searchbar JS presses them on Enter / Tab or click away, and Esc.
     */
    protected function renderInline()
    {
        $filterable = $this->getFilterable();

        if (!$filterable?->supportsInlineEdit()) {
            return _RulePill(
                $filterable?->getFilterName(),
                _Flex(
                    $this->renderContent(),
                    $this->stateAction(_Link()->icon('x'), 'searchstate.delete-rule'),
                )->class('gap-2'),
            );
        }

        $operator = method_exists($this, 'getOperator') ? $this->getOperator() : null;
        $isNew = $this->isPendingValue();
        // Named by the rule's id: two pills of one filter have their own field, whatever their positions.
        $name = 'inline_' . $this->getId();

        // Pickers apply on change (a date picked): they blur before their value is set.
        $onApply = fn($e) => $e->post('searchstate.set-inline-value', $this->stateParams())
            ->withAllFormValues()->refresh($this->refreshTargets());

        return _RuleInlinePill(
            $filterable->getFilterName(),
            _Flex(
                !$operator ? null : _Html(__($operator->label()))->class('text-xs text-gray-500 whitespace-nowrap'),
                $filterable->getInlineEditor($name, $isNew ? null : $this->getValue(), $operator, $onApply),
                // Out of the Tab order: Tab leaves the editor (and applies it) instead of stopping on ✓ then ✕.
                $this->stateAction(
                    _Link()->icon(_Sax('tick-circle', 16))->class('inline-filter-apply text-greenmain')->title('filter.apply-enter')
                        ->attr(['tabindex' => '-1']),
                    'searchstate.set-inline-value',
                ),
                $this->stateAction(
                    _Link()->icon('x')->class('inline-filter-cancel text-gray-500 hover:text-danger')
                        ->title($isNew ? 'filter.remove-esc' : 'filter.cancel-esc')->attr(['tabindex' => '-1']),
                    'searchstate.cancel-rule-edit',
                ),
            )->class('items-center gap-1'),
            $filterable->inlineEnterApplies(),
        );
    }

    /**
     * $el posts $route for this rule, then refreshes the komponents showing its state (refreshTargets()). The pill
     * locks meanwhile, so a double click can't send the request twice.
     */
    protected function stateAction($el, string $route, array $params = [])
    {
        return $el->onClick(fn($e) => $e->run('() => { window.searchbarBusy && searchbarBusy(); }')
            && $e->post($route, $this->stateParams($params))->withAllFormValues()->refresh($this->refreshTargets()));
    }

    /**
     * What a change of this rule refreshes: its state's table, or the navbar's pills, filters column and results (not
     * the input: its focus and typed text stay). Without a context (never rendered so), the komponent rendering it.
     */
    protected function refreshTargets(): ?array
    {
        return $this->hasContext() ? $this->getContext()->refreshTargets() : null;
    }

    /**
     * Route params: the rule's id, and its filter key (a request whose id names another filter changes nothing), in
     * its state. Never its position: positions shifted when rules changed in another tab or request.
     */
    protected function stateParams(array $params = []): array
    {
        $params = array_merge(['ruleId' => $this->getId(), 'key' => $this->getKeyReference()], $params);

        return $this->hasContext() ? $this->getContext()->stateParams($params) : $params;
    }

    /**
     * What a stored state keeps of this rule besides its id and filter key (StateCodec, format v2): what the user chose
     * (its operator's value, its value). Never its class or SQL column: Filterable::ruleFromData() rebuilds the rule
     * from the filter as declared when it is read. Plain data only (scalars, arrays).
     */
    public function toData(): array
    {
        $operator = method_exists($this, 'getOperator') ? $this->getOperator() : null;

        return ($operator instanceof \BackedEnum ? ['op' => $operator->value] : [])
            + ['value' => method_exists($this, 'getValue') ? $this->getValue() : null];
    }

    public function setEditing(bool $editing = true)
    {
        $this->editing = $editing;

        return $this;
    }

    public function isEditing(): bool
    {
        return $this->editing;
    }

    public function isPendingValue(): bool
    {
        return false;
    }

    /**
     * "Field: its pill's text" ("Email: contains gmail", "Gender: in Female, Male"), plain text; the pill's text
     * alone when it already is the field's name (a scope filter). Null for a pending pill (it doesn't filter).
     */
    public function describe(): ?string
    {
        if ($this->isPendingValue()) {
            return null;
        }

        $plain = fn($text) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5)));

        // Kompo labels are translated when the element is built (pill texts escape their values: decoded here).
        $content = $this->renderContent();
        $text = $plain(is_object($content) && isset($content->label) && is_string($content->label) ? $content->label : '');
        $name = $plain(__((string) $this->getFilterable()?->getFilterName()));

        return match (true) {
            $name === '' || $text === $name => $text !== '' ? $text : null,
            $text === '' => $name,
            default => $name . ': ' . $text,
        };
    }
}
