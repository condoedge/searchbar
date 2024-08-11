<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType;

class EnumEntityType extends EntityType
{
    protected $enum;

    public function __construct($enum, bool $allowAllOption = false)
    {
        $this->enum = $enum;
        $this->allowAllOption = $allowAllOption;
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

    public function getLabel($value)
    {
        return $this->from($value)->label();
    }
}