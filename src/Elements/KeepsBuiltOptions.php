<?php

namespace Kompo\Searchbar\Elements;

/**
 * A select that searches its options on the server keeps the options it was built with (its selected values,
 * labelled by the filter). Kompo's display step (Select::prepareForFront -> retrieveOptionsFromValue) replaced them
 * through a komponent method called with the value alone, which can't tell which filter a value belongs to (the
 * custom filters modal shows a team row and a person row at once); without such a method it ran the search with ''
 * and indexed the result by the value (undefined index past the first results).
 *
 * Elements returned by self-methods and Laravel routes skip that step anyway: built this way, they look the same.
 * Re-check on Kompo upgrades (protected Select::retrieveOptionsFromValue()).
 */
trait KeepsBuiltOptions
{
    protected function retrieveOptionsFromValue($komponent)
    {
    }
}
