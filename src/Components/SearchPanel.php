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

        $typeInstance = $this->state->getSearchableInstance();

        return _Rows(
            _Html('loading...')->class('text-lg p-4')->id('search-panel-loading' . $this->serviceKey)->class('hidden'),
            _Rows(
                _FlexEnd(
                    _Link()->icon('x')->class('text-3xl mb-3 mt-1 absolute top-0 right-5 z-10')->selfPost('closeSearch')->refresh(),
                ),
                _Columns(
                    _Rows(
                        _Html('filter.filter')->icon('filter')->class('ml-4 mt-2 font-semibold text-level1'),
                        $typeInstance ? $this->sections($typeInstance) : _Rows(
                            searchService()->optionsSearchables()
                        )->class('py-4'),
                    )->class('px-2')->col('col-md-3'),

    
                    _Rows(
                        $this->instanciateSearchKomponent(EnhancedSearchbar::class),
                    )->class('items-start !pb-2 py-4 overflow-y-auto mini-scroll')->col('col-md-6')->style('height:420px'),
                
                    _Rows(
                        $this->instanciateSearchKomponent(FavoritesSearches::class),

                        _Rows(collect($typeInstance?->getPremadeRules())->map(fn($r) => $r->getToggle()))->class('mt-6')
                    )->col('col-md-3 pt-6'),
                ),
            )->class('relative'),

        )->class('max-w-6xl w-screen md:w-full bg-white rounded-b-2xl border-gray-200 shadow-xl border-b border-l border-r border-level4 border-t-none px-2 py-2 -mt-2');
    }

    protected function sections($searchableI)
    {
        return _Rows(
            _Link('filter.back')->icon(_sax('arrow-left',20))->selfGet('getBack')->refresh('navbar-search')->class('mt-4 border-b py-4 border-level4 text-black font-semibold'),
            _Rows($searchableI->decoratedSections()->map(fn($s) => $s->showOptions()->class('border-b py-4 border-level4'))),
            _Link('filter.custom-filters')->class('text-black font-semibold py-4')
                ->selfGet('getCustomFiltersModal')->inModal(),
        )->class('pl-4');
    }

    public function getCustomFiltersModal()
    {
        return $this->instanciateSearchKomponent(CustomFiltersModal::class);
    }
}
