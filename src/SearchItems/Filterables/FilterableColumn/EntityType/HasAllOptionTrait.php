<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType;

trait HasAllOptionTrait 
{
    protected $allowAllOption = false;

    public function addAllOption($options)
    {
        if($this->allowAllOption) {
            $options->prepend('translate.all', 'all');
        }

        return $options;
    }

    public function parseLabelWithAllOption($value, $defaultCallback = null)
    {
        if($value == 'all') {
            return 'translate.all';
        }

        return $defaultCallback ? $defaultCallback() : $value;
    }

    public function allowAllOption()
    {
        $this->allowAllOption = true;

        return $this;
    }

    public function hasAllowAllOption()
    {
        return $this->allowAllOption;
    }
}