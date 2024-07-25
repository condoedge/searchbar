<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType;

class SelectEntityType extends EntityType
{
    protected $options;

    public function __construct($options)
    {
        $this->options = $options;
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
}