<?php

namespace Kompo\Searchbar\Components;

use Condoedge\Utils\Kompo\Common\Modal;

/**
 * "Share this view" of a table (HasSearchbarViews::getSearchbarShareModal()): the URL opening a copy of the table's
 * filters, to copy. Built by the table with its url prop (null: this view can't be shared from here).
 */
class ShareSearchViewModal extends Modal
{
    public $id = 'searchbar-share-view-modal';
    protected $_Title = 'filter.share-view';
    public $class = 'max-w-2xl w-screen';

    protected $noHeaderButtons = true;

    public function body()
    {
        // A prop set by the server when the modal is built (modals aren't routes: never from a URL).
        $url = is_string($this->prop('url')) ? $this->prop('url') : null;

        if (!$url) {
            return _Html('filter.share-unavailable')->class('text-sm text-gray-500');
        }

        return _Rows(
            _Html(__('filter.share-view-hint', ['days' => max(1, (int) config('searchbar.link-lifetime-days', 10))]))
                ->class('text-sm text-gray-500 mb-4'),
            _Input()->name('searchbar_share_url', false)->value($url)->readOnly()->noAutocomplete()
                ->class('mb-3 searchbar-share-url'),
            _FlexEnd(
                _Html('filter.link-copied')->class('hidden text-sm text-greenmain searchbar-share-copied'),
                _Button('filter.copy-link')->icon(_Sax('copy', 16))->run($this->copyJs($url)),
            )->class('gap-4 items-center'),
        );
    }

    /**
     * Copies $url. The clipboard API needs a secure context (https, localhost): elsewhere the field's text is selected
     * and copied with the older command, so the user can still copy it by hand when both fail.
     */
    protected function copyJs(string $url): string
    {
        $json = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        return '() => {'
            . ' const url = ' . $json . ';'
            . ' const done = () => document.querySelectorAll(".searchbar-share-copied").forEach((n) => n.classList.remove("hidden"));'
            . ' const byHand = () => { const input = document.querySelector(".searchbar-share-url input"); if (!input) return;'
            . ' input.focus(); input.select(); try { document.execCommand("copy") && done(); } catch (e) {} };'
            . ' if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(url).then(done, byHand); } else { byHand(); }'
            . ' }';
    }
}
