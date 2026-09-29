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

    /** Labels of several values, keyed by value. Types backed by a table override it with one query. */
    public function getLabels(array $values)
    {
        return collect($values)->mapWithKeys(fn($value) => [$value => $this->getLabel($value)]);
    }

    /**
     * The options a select input offers for this type. The selected values are passed so a type that caps its
     * options (see RelationEntityType) still shows them.
     */
    public function optionsForInput(array $selected = [])
    {
        return $this->optionsWithLabels();
    }

    // SERVER SEARCH (selects of big lists: only RelationEntityType can switch, see there)

    /** Whether this type can find its records from typed text. */
    public function canSearchOnServer(): bool
    {
        return false;
    }

    /** Whether a select of this type searches its options on the server as the user types, instead of loading them. */
    public function searchesOnServer(): bool
    {
        return false;
    }

    /** Options matching $search (value => escaped label), for a select that searches on the server. */
    public function searchOptions(?string $search)
    {
        return collect();
    }

    /** The selected values as labelled options, for a select that searches the others on the server. */
    public function selectedOptions(array $selected = [])
    {
        return $this->optionsForInput($selected);
    }
}
