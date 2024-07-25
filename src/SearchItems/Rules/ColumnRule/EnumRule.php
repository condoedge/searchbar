<?php

namespace Kompo\Searchbar\SearchItems\Rules\ColumnRule;

class EnumRule extends WithEntityRule
{    
    public function renderContent()
    {
        return _Html($this->visualValue());
    }
}