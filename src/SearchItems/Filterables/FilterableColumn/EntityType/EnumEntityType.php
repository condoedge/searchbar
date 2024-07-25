<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType;

class EnumEntityType extends EntityType
{
    protected $enum;

    public function __construct($enum)
    {
        $this->enum = $enum;
    }

    public function optionsWithLabels()
    {
        return $this->enum::optionsWithLabels();
    }

    public function getValue()
    {
        return $this->enum;
    }

    public function from($value)
    {
        return $this->enum::from((int) $value);
    }
}