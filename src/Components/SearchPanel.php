<?php

namespace Kompo\Searchbar\Components;

use Kompo\Form;
use Kompo\Searchbar\Components\CustomFiltersModal;
use Kompo\Searchbar\Components\EnhancedSearchbar;
use Kompo\Searchbar\Components\SearchKomponentUtils;
use Kompo\Searchbar\Components\SearchStateRequestUtils;

class SearchPanel extends Form
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $id = 'search-panel';

    public function created()
    {
        $this->setSearchProps();

        $this->onLoad(fn($e) => $e->run('() => {setTimeout(() => searchLoadingOff("search-panel-loading' . $this->serviceKey . '"), 100)}'));
    }

    public function render()
    {
        if (!$this->state->isOpen()) {
            return null;
        }

        $searchableI = $this->state->getSearchableInstance();

        return _Rows(
            _Html('loading...')->class('text-lg p-4')->id('search-panel-loading' . $this->serviceKey)->class('hidden'),
            _Flex(
                $searchableI ? $this->sections($searchableI) : _Rows(
                    searchService()->optionsSearchables()
                )->class('py-4 px-2'),

                _Rows(
                    _FlexEnd(
                        _Link()->icon('x')->class('text-3xl mb-3 mt-1 absolute top-0')->selfPost('closeSearch')->refresh(),
                    ),

                    _Rows(
                        $this->instanciateSearchKomponent(EnhancedSearchbar::class),
                    )->class('items-start pr-10')
                )->class('flex-1 overflow-y-auto mini-scroll !pb-2 relative py-4')->style('height:380px')
            )->alignStart()
        )->class('w-screen md:w-full bg-white rounded-b-2xl border border-gray-200 shadow-xl border-b border-l border-r border-greenmain px-2 py-2');
    }

    protected function sections($searchableI)
    {
        return _Rows(
            _Link('filter.back')->icon(_sax('arrow-left',20))->selfGet('getBack')->refresh('navbar-search')->class('mt-4 border-b py-4 border-level4 text-black font-semibold'),
            _Rows($searchableI->decoratedSections()->map(fn($s) => $s->showOptions()->class('border-b py-4 border-level4'))),
            _Link('filter.custom-filters')->class('text-black font-semibold py-4')
                ->selfGet('getCustomFiltersModal')->inModal(),
        )->class('px-4');
    }

    public function getCustomFiltersModal()
    {
        return $this->instanciateSearchKomponent(CustomFiltersModal::class);
    }
}
