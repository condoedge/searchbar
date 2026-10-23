<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType;

trait HasAllOptionTrait
{
    protected $allowAllOption = false;

    public function addAllOption($options)
    {
        if($this->allowAllOption) {
            // Translated here: option labels and pill values are shown as given (the raw key was displayed).
            $options->prepend(__('filter.all-options'), 'all');
        }

        return $options;
    }

    public function parseLabelWithAllOption($value, $defaultCallback = null)
    {
        if($value == 'all') {
            return __('filter.all');
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
