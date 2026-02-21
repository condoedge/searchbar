<?php

namespace Kompo\Searchbar\SearchItems\Sections;

use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\SearchItem;
use Exception;

abstract class SearchSection extends SearchItem
{
    protected $filterableClass = Filterable::class;

    protected function getFilterable($key): Filterable|Exception
	{
        $filterable = $this->searchContextService->getStore()->getState()->getSearchableInstance()->filterable($key);

        if(!$filterable instanceof $this->filterableClass) {
            throw new Exception('Filterable must be an instance of ' . $this->filterableClass);
        }

		return $filterable;
	}

    public function showOptions()
    {
        return _Rows(
            $this->getSectionLabel() ? _Html($this->getSectionLabel())->class('text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2') : null,
            _Flex(
                $this->options()->map(function($option, $index) {
                    return $this->linkOption($option, $index);
                }),
            )->class('flex-wrap gap-2'),
        );
    }

    protected function linkOption($option, $index)
    {
        $isSelected = $this->isOptionSelected($index);

        $link = $isSelected
            ? _Link($option)->icon(_Sax('tick-circle', 16))
            : _Link($option);

        return $link
            ->post('searchstate.toggle-section-rule', ['rule' => serialize($this->getRule($index))])
            ->withAllFormValues()
            ->refresh('navbar-search')
            ->class($this->chipClasses($isSelected));
    }

    protected function chipClasses($isSelected)
    {
        $base = 'rounded-lg px-3 py-1.5 border text-sm cursor-pointer transition-colors';

        return $isSelected
            ? $base . ' bg-greenmain text-white border-greenmain font-semibold'
            : $base . ' bg-level4 text-level1 border-level4 hover:border-greenmain hover:bg-green-50';
    }

    public function isOptionSelected($index): bool
    {
        return false;
    }

    protected function getSectionLabel(): ?string
    {
        return null;
    }

    protected function getActiveFilterableRules()
    {
        return $this->searchContextService->getStore()->getState()->getFilterableRules();
    }

	abstract public function getRule($type);

    abstract function options();
}