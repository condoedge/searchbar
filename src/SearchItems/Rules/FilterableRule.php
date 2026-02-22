<?php

namespace Kompo\Searchbar\SearchItems\Rules;

use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn;
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

    public function getFilterable(Searchable $searchable = null)
    {
        $searchable = $searchable ?? $this->searchable ?? $this->getState()->getSearchableInstance();

        if (!$searchable) {
            return null;
        }

        return $searchable->filterable($this->keyReference)->setAssignedRule($this);
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

    public function render($index, $withDeleteButton = true)
    {
        if ($this->isPendingValue()) {
            return $this->renderInline($index);
        }

        return _RulePill(
            $this->getFilterable()?->getFilterName(),
            _Flex(
                $this->renderContent(),
                !$withDeleteButton ? null : _Link()->icon('x')->post('searchstate.delete-rule', ['i' => $index])->withAllFormValues()
                    ->refresh('navbar-search'),
            )->class('gap-2'),
        );
    }

    protected function renderInline($index)
    {
        $filterable = $this->getFilterable();

        if (!$filterable instanceof FilterableColumn) {
            return _RulePill(
                $filterable?->getFilterName(),
                _Flex(
                    $this->renderContent(),
                    _Link()->icon('x')->post('searchstate.delete-rule', ['i' => $index])->withAllFormValues()
                        ->refresh('navbar-search'),
                )->class('gap-2'),
            );
        }

        $key = $this->getKeyReference();

        $onEnter = fn($e) => $e->post('searchstate.set-inline-value', ['key' => $key])
            ->withAllFormValues()
            ->refresh('navbar-search');

        $inlineInput = $filterable->getInlineInput('inline_' . $key, $onEnter);

        return _Flex(
            _Html($filterable->getFilterName())
                ->class('text-sm bg-level1 text-white px-2 p-1 rounded-l-md whitespace-nowrap'),
            _Flex(
                $inlineInput,
                _Link()->icon('x')
                    ->post('searchstate.delete-rule', ['i' => $index])
                    ->withAllFormValues()
                    ->refresh('navbar-search'),
            )->class('items-center gap-1 bg-level4 px-2 p-1 rounded-r-md'),
        )->class('rounded-md w-max overflow-hidden items-center');
    }

    public function isPendingValue(): bool
    {
        return false;
    }
}