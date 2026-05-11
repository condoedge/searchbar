<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Form;

class SearchPanel extends Form
{
    use SearchStateRequestUtils;
    use SearchKomponentUtils;

    public $id = 'search-panel';

    public function created()
    {
        $this->setSearchProps();
    }

    public function render()
    {
        $typeInstance = $this->state->getSearchableInstance();
        $sk = $this->serviceKey;
        $bodyId = 'search-results-lazy-body-' . $sk;

        return _Rows(
            _Hidden()->name('serviceKey')->default($this->serviceKey),
            _Hidden()->name('storeKey')->default($this->storeKey),
            _Html('loading...')->class('text-lg p-4')->id('search-panel-loading' . $sk)->class('hidden'),

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

            // MAIN: Results (LEFT) + Filters/Favorites (RIGHT)
            _Flex(
                // LEFT: results column — lazy. Skeleton paints instantly;
                // the full EnhancedSearchbar (entity header + CTA + rows)
                // streams in via selfGet → inPanel on mount. Same shape as
                // RolesAndPermissionMatrix' lazy section pattern.
                _Rows(
                    _Panel(
                        $this->resultsSkeleton(),
                    )->id($bodyId)->class('w-full'),
                    _Hidden()->onLoad(fn($e) => $e->selfGet('loadResultsColumn')->inPanel($bodyId)),
                )->class('relative items-start !pb-2 !pt-0 py-4 px-4 flex-1 min-w-0 self-stretch overflow-y-auto mini-scroll')
                  ->class($this->state->getSearchableEntity() ? '' : '-mt-8'),

                // RIGHT: filters/favorites panel
                _Rows(
                    _Rows(
                        $typeInstance ? $this->sections($typeInstance) : _Rows(
                            searchService()->optionsSearchables()
                        )->class('py-2'),
                    )->id('search-content-filters')->class('overflow-y-auto overflow-x-hidden mini-scroll flex-1'),

                    _Rows(
                        $this->instanciateSearchKomponent(FavoritesSearches::class),
                    )->id('search-content-favorites')->class('px-2 py-2 overflow-y-auto mini-scroll flex-1 min-h-[40vh] md:min-h-[30vh] lg:min-h-[35vh]')->style('display:none'),
                )->class('overflow-y-auto overflow-x-hidden mini-scroll shrink-0 border-l border-level4 self-stretch')->id('search-filters-panel')->style('width:33.333%;transition:width 0.25s ease,opacity 0.2s ease,margin-right 0.25s ease'),
            )->class('w-full flex-1')->style('max-height: 95vh;'),

            _Hidden()->onLoad(fn($e) => $e->run('() => {setTimeout(() => { searchLoadingOff("search-panel-loading' . $sk . '"); if(typeof switchSearchTab === "function") switchSearchTab("filters"); }, 100)}')),

        )->class('max-w-6xl w-screen md:w-full h-full md:h-auto bg-white rounded-b-2xl border-gray-200 shadow-xl border-b border-l border-r border-level4 border-t-none px-2 py-2 -mt-2');
    }

    public function loadResultsColumn()
    {
        return $this->instanciateSearchKomponent(EnhancedSearchbar::class);
    }

    protected function resultsSkeleton()
    {
        $bar = 'animate-pulse rounded-lg bg-gray-200';
        $card = fn() => _Flex(
            _Rows()->class("$bar w-10 h-10 rounded-full shrink-0"),
            _Rows(
                _Rows()->class("$bar h-4 w-40 mb-2"),
                _Rows()->class("$bar h-3 w-56 mb-2 opacity-60"),
                _Rows()->class("$bar h-3 w-32 opacity-60"),
            )->class('flex-1 min-w-0'),
            _Rows()->class("$bar h-6 w-24 self-start shrink-0"),
        )->class('gap-3 p-3 mb-2 rounded-xl border border-level4 items-center');

        return _Rows(
            _Rows()->class("$bar h-5 w-28 mb-3"),
            _Rows()->class('animate-pulse rounded-lg h-12 w-full mb-4 bg-greenmain opacity-70'),
            $card(), $card(), $card(), $card(),
        );
    }

    protected function sections($searchableI)
    {
        return _Rows(
            _Rows($searchableI->decoratedSections()->map(fn($s) => $s->showOptions()->class('border-b py-4 border-level4'))),
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
