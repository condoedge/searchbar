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
        // The "all" flag offered nothing (only relations added the option); unchanged without it.
        return $this->allowAllOption ? $this->addAllOption(collect($this->enum::optionsWithLabels())) : $this->enum::optionsWithLabels();
    }

    public function getValue()
    {
        return $this->enum;
    }

    public function from($value)
    {
        if (!is_scalar($value)) {
            return null;
        }

        // (int) for string-backed enums turned every value into 0: they never got a label.
        $isIntBacked = (string) (new \ReflectionEnum($this->enum))->getBackingType() === 'int';

        return $this->enum::tryFrom($isIntBacked ? (int) $value : (string) $value);
    }

    public function getLabel($value)
    {
        return $this->from($value)?->label();
    }
}
