<?php

namespace Kompo\Searchbar\Components;

use Kompo\Auth\Common\Modal;

class AbstractGroupedActionModal extends Modal
{
    use SearchKomponentUtils;

    protected $noHeaderButtons = true;

    protected $ids;

    public function created()
    {
        $this->setSearchProps();

        $this->ids = explode(',', $this->prop('itemIds'));
    }
}