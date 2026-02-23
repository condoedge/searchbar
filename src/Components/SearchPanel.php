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

        $this->onLoad(fn($e) => $e->run('() => {setTimeout(() => { searchLoadingOff("search-panel-loading' . $this->serviceKey . '"); if(typeof switchSearchTab === "function") switchSearchTab("filters"); }, 100)}'));
    }

    public function render()
    {
        $typeInstance = $this->state->getSearchableInstance();

        return _Rows(
            _Hidden()->name('serviceKey')->default($this->serviceKey),
            _Hidden()->name('storeKey')->default($this->storeKey),
            _Html('loading...')->class('text-lg p-4')->id('search-panel-loading' . $this->serviceKey)->class('hidden'),

            // TOP BAR: Back button (left) + Toggle tabs + retract arrow (right)
            _FlexBetween(
                $typeInstance ? _Link('filter.back')->icon(_Sax('arrow-left', 20))->post('searchstate.get-back')->withAllFormValues()->refresh('navbar-search')->class('text-black font-semibold') : _Rows(),
                _Flex(
                    _Flex(
                        _Link('filter.filters')->icon(_Sax('filter', 16))->class('font-semibold text-lg cursor-pointer select-none px-3 py-1 rounded-lg transition-all search-tab-btn search-tab-active')
                            ->id('search-tab-filters')
                            ->run('() => { switchSearchTab("filters"); }'),
                        _Link('filter.favorites')->icon(_Sax('star', 16))->class('font-semibold text-lg cursor-pointer select-none px-3 py-1 rounded-lg transition-all search-tab-btn text-gray-400')
                            ->id('search-tab-favorites')
                            ->run('() => { switchSearchTab("favorites"); }'),
                    )->class('items-center gap-1 bg-gray-100 rounded-lg p-0.5'),
                    _Link()->icon(_Sax('arrow-right', 16))->class('transition-transform cursor-pointer select-none ml-2 bg-greenmain text-white rounded-md w-8 h-8 flex items-center justify-center hover:bg-green-700')->id('search-filters-arrow')
                        ->run('() => { toggleSearchFilters(); }'),
                )->class('items-center'),
            )->class('px-4 py-2'),

            // MAIN: Results (LEFT) + Filters/Favorites (RIGHT, collapsible width)
            _Flex(
                // LEFT: Results (flex-1, takes all space when panel collapsed)
                _Rows(
                    $this->instanciateSearchKomponent(EnhancedSearchbar::class),
                )->class('items-start !pb-2 py-4 px-4 overflow-y-auto mini-scroll flex-1 min-w-0 self-stretch max-h-[95vh] md:max-h-[65vh]'),

                // RIGHT: Filters/Favorites panel (width collapses to 0)
                _Rows(
                    // Filters content
                    _Rows(
                        $typeInstance ? $this->sections($typeInstance) : _Rows(
                            searchService()->optionsSearchables()
                        )->class('py-2'),
                    )->id('search-content-filters')->class('overflow-y-auto overflow-x-hidden mini-scroll flex-1'),

                    // Favorites content (hidden by default)
                    _Rows(
                        $this->instanciateSearchKomponent(FavoritesSearches::class),
                    )->id('search-content-favorites')->class('px-2 py-2 overflow-y-auto mini-scroll flex-1 min-h-[40vh] md:min-h-[30vh] lg:min-h-[35vh]')->style('display:none'),
                )->class('overflow-y-auto overflow-x-hidden mini-scroll shrink-0 border-l border-level4 self-stretch')->id('search-filters-panel')->style('width:33.333%;transition:width 0.25s ease,opacity 0.2s ease,margin-right 0.25s ease'),
            )->class('w-full overflow-y-auto flex-1')->style('max-height: 95vh;'),

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
