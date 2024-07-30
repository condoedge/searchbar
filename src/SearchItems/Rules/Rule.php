<?php

namespace Kompo\Searchbar\SearchItems\Rules;

use Kompo\Searchbar\SearchItems\SearchItem;

abstract class Rule extends SearchItem
{
    const SEPARATOR = ',';

    public function query($query)
    {
        return $this->decorateQuery($query);
    }

    abstract public function decorateQuery($query);

    abstract public function toArray();
    abstract public function renderContent();

    public function getState()
    {
        return $this->searchContextService->getStore()->getState();
    }

    public function render($index, $withDeleteButton = true)
    {
        return _Rows(
            _Flex(
                $this->renderContent(),
                !$withDeleteButton ? null : _Link()->icon('trash')->selfPost('deleteRule', ['i' => $index])
                    ->refresh('navbar-search'),
            )->class('gap-4'),
        )->asPill()->class('bg-level4');
    }
}