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

    protected function calculateOptions()
    {
        if (is_callable($this->options)) {
            $this->options = call_user_func($this->options);
        }

        return $this->options;
    }

    public function optionsWithLabels()
    {
        return $this->calculateOptions();
    }

    public function getValue()
    {
        return $this->calculateOptions();
    }

    public function from($value)
    {
        return $this->calculateOptions()[$value] ?? $value;
    }

    public function getLabel($value)
    {
        return $this->calculateOptions()[$value] ?? $value;
    }
}