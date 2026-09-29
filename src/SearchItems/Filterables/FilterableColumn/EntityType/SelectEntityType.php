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
        // The "all" flag offered nothing (only relations added the option); unchanged without it.
        return $this->allowAllOption ? $this->addAllOption(collect($this->calculateOptions())) : $this->calculateOptions();
    }

    /**
     * The select's options, labels escaped as RelationEntityType's: Kompo renders them as HTML, and hosts build them
     * from stored text (SISC: pluck() of the codes and descriptions admins type).
     */
    public function optionsForInput(array $selected = [])
    {
        return collect($this->optionsWithLabels())->map(fn($label) => is_string($label) ? e($label) : $label);
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