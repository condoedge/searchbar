<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;
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
        $typeInstance = $this->state->getSearchableInstance();

        return _Rows(
            _Hidden()->name('serviceKey')->default($this->serviceKey),
            _Hidden()->name('storeKey')->default($this->storeKey),
            _Html('loading...')->class('text-lg p-4')->id('search-panel-loading' . $this->serviceKey)->class('hidden'),

            // Top bar: Filter toggle + Back button
            _FlexBetween(
                _Flex(
                    _Sax('filter', 16)->class('text-level1'),
                    _Html('filter.filters')->class('font-semibold text-level1 text-lg'),
                    _Sax('arrow-left', 16)->class('transition-transform search-filters-arrow')->id('search-filters-arrow'),
                )->class('items-center gap-2 cursor-pointer select-none')
                  ->onClick(fn($e) => $e->run('() => { toggleSearchFilters(); }')),
                $typeInstance ? _Link('filter.back')->icon(_sax('arrow-left', 20))->post('searchstate.get-back')->withAllFormValues()->refresh('navbar-search')->class('text-black font-semibold') : null,
            )->class('px-4 py-2'),

            // Main content: filters + results side by side (flex, not grid)
            _Flex(
                // LEFT: Filters column (collapsible)
                _Rows(
                    $typeInstance ? $this->sections($typeInstance) : _Rows(
                        searchService()->optionsSearchables()
                    )->class('py-2'),
                )->class('overflow-y-auto overflow-x-hidden mini-scroll px-2 shrink-0 border-r border-level4 self-stretch')->id('search-filters-panel')->style('width:33.333%;transition:width 0.25s ease,opacity 0.2s ease,margin-left 0.25s ease'),

                // RIGHT: Results column
                _Rows(
                    $this->instanciateSearchKomponent(EnhancedSearchbar::class),
                )->class('items-start !pb-2 py-4 pl-4 overflow-y-auto mini-scroll flex-1 min-w-0 self-stretch'),
            )->class('w-full overflow-hidden flex-1'),

            // BOTTOM: Favorites section (always visible, full width)
            _Rows(
                $this->instanciateSearchKomponent(FavoritesSearches::class),
            )->class('border-t border-level4 pt-3 px-4 mt-2'),

        )->class('max-w-6xl w-screen md:w-full h-full md:h-auto bg-white rounded-b-2xl border-gray-200 shadow-xl border-b border-l border-r border-level4 border-t-none px-2 py-2 -mt-2');
    }

    protected function sections($searchableI)
    {
        return _Rows(
            _Rows($searchableI->decoratedSections()->map(fn($s) => $s->showOptions()->class('border-b py-4 border-level4'))),
            // Premade rules (toggles) under filters
            _Rows(collect($searchableI->getPremadeRules())->map(fn($r) => $r->getToggle()))->class('py-3'),
            _Link('filter.custom-filters')->icon(_Sax('add-circle', 16))->class('bg-greenmain text-white font-semibold rounded-lg px-3 py-2 text-sm mt-2 inline-flex items-center gap-2 hover:bg-green-700')
                ->selfGet('getCustomFiltersModal')->inModal(),
        )->class('pl-4');
    }

    public function getCustomFiltersModal()
    {
        return $this->instanciateSearchKomponent(CustomFiltersModal::class);
    }
}
