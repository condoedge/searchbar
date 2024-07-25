<?php

namespace Kompo\Searchbar\Searchable;

interface RelationSearchable
{
    public static function parseOptions($options);
    public function label();
}