<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType;

class SelectEntityType extends EntityType
{
    protected $options;

    public function __construct($options, $allowAllOption = false)
    {
        $this->options = $options;
        $this->allowAllOption = $allowAllOption;
    }

    public function optionsWithLabels()
    {
        return $this->options;
    }

    public function getValue()
    {
        return $this->options;
    }

    public function from($value)
    {
        return $this->options[$value];
    }

    public function getLabel($value)
    {
        return $this->from($value);
    }
}