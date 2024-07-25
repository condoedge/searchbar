<?php

namespace Kompo\Searchbar\Components;

use Kompo\Modal;

class CustomFiltersModal extends Modal
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $id = 'custom-filters-modal';
    protected $_Title = 'translate.filters';

    public function headerButtons()
	{
        return _Button('translate.new-rule')->selfGet('addRuleModal')->inModal();
	}

    public function body()
    {
        $typeInstance = $this->state->getSearchableInstance();

        return _Rows(
            _Rows(
                !$this->state->getSearchableEntity() ? _Html('translate.no-rules')->class('text-center mb-2') : null,
                _Rows(
                    _FlexBetween(
                        _Html('translate.searchable'),

                        _Select()->name('searchableEntity')->options(searchService()->optionsSearchables())
                            ->default($this->state->getSearchableEntity())
                            ->selfPost('selectSearchableEntity')->refresh('navbar-search')
                            ->class('!mb-0'),
                        
                    )->class('items-center gap-3 w-full'),
                )->class('mb-4'),
                _Rows($this->state->getFilterableRules()->map(function($r, $i) use ($typeInstance) {
                    $colInfo = $r->getFilterable($typeInstance);

                    return _Rows(
                        _FlexBetween(
                            _FlexBetween(
                                _Html($colInfo->getFilterName()),

                                $colInfo->formRow($r, $i),
                                
                            )->class('items-center gap-3 w-full'),
                            _DeleteLink()->icon(_Sax('trash', 22))->class("text-gray-700 hover:text-danger")
                                ->selfPost('deleteRule', ['i' => $i])->refresh()->refresh('navbar-search'),
                        )->class('gap-6'),
                    );
                }))->class('gap-y-4'),
            ),
        );
    }

    public function footer()
    {
        return null;
    }

    public function executeCustomFilterableFunction($i, $function)
    {
        $rule = $this->state->getRules()->get($i);

        return $rule->getFilterable($this->state->getSearchableInstance())->executeCustomMethod($function, $i);
    }

    public function addRuleModal()
    {
        $searchableI = $this->state->getSearchableInstance();

        $cols = collect($searchableI::filterables());

        return _Rows(
            _Html('translate.add-rule')->class('text-2xl font-semibold mb-4'),
            _Rows($cols->map(function($col, $key) {
                return _Link($col->getName())->selfGet('getRuleForm', ['i' => $key])->inPanel('rule-details-form');
            }))->class('mb-4 gap-2'),

            _Panel()->id('rule-details-form'),
        )->class('p-4');
    }

    public function getRuleForm($key)
    {
        return $this->searchableInstance->filterable($key)->form($key, searchService()->getStoreKey());
    }
}