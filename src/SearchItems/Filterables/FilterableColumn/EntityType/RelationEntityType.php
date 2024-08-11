<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\EntityType;

use Kompo\Searchbar\Searchable\RelationSearchable;

class RelationEntityType extends EntityType
{
    protected $relation;
    protected $baseQuery;

    public function __construct($relation, string $baseQuery, bool $allowAllOption = false)
    {
        if (!is_subclass_of($relation, RelationSearchable::class)) {
            throw new \Exception('Relation must implement RelationSearchable interface');
        }

        $this->relation = $relation;
        $this->baseQuery = $baseQuery;
        $this->allowAllOption = $allowAllOption;
    }

    public function optionsWithLabels()
    {
        $baseQuery = $this->baseQuery;

        return $this->addAllOption($this->relation::parseOptions($this->relation::$baseQuery()->get()));
    }

    public function getValue()
    {
        return $this->relation;
    }

    public function from($value)
    {
        return $this->relation::find($value);
    }

    public function getLabel($value)
    {
        return $this->parseLabelWithAllOption($value, fn() => $this->from($value)->label());
    }
}