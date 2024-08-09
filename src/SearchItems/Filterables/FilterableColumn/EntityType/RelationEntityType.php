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
    }

    public function optionsWithLabels()
    {
        $baseQuery = $this->baseQuery;

        $options = $this->relation::parseOptions($this->relation::$baseQuery()->get());
        $options->prepend('all', 'All');
        
        return $options;
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
        return $this->from($value)->label();
    }
}