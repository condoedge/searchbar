<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType;
use Kompo\Searchbar\SearchItems\SearchItem;

abstract class EntityType extends SearchItem
{
    use HasAllOptionTrait;
    
    abstract public function optionsWithLabels();
    abstract public function from($value);
    abstract public function getLabel($value);

    abstract public function getValue();
}