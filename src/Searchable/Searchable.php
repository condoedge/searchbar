<?php

namespace Kompo\Searchbar\Searchable;

interface Searchable
{
    public function searchElement($result, ?string $search);

    public static function baseSearchQuery();
    public function getInitialRule();
    public static function searchableName();

    /**
	 * @return \Kompo\Searchbar\SearchItems\Filterables\Filterable[]
	 */
    public static function filterables();
    public function filterable(string $column): \Kompo\Searchbar\SearchItems\Filterables\Filterable;
    
    public static function sections();
    public function decoratedSections();

    public function getTableClass();
    public function getTableClassInstance($parameters = []);

    public function getEagerRelationsKeys();
}
