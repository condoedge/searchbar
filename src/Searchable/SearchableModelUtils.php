<?php

namespace Kompo\Searchbar\Searchable;

use Kompo\Searchbar\InjectableContextTrait;
use Kompo\Searchbar\SearchItems\Filterables\Filterable;

trait SearchableModelUtils
{
	use InjectableContextTrait;

	public static function getBaseFilterable()
	{
		if (defined(self::class . '::BASE_FILTERABLE')) {
			return self::BASE_FILTERABLE;
		}

		return 'name_filter';
	}

	public function getTableClass()
	{
		return config('searchbar.base_result_table_namespace') . '\\Search' . class_basename(self::class) . 'Table';
	}

	public final function getTableClassInstance($parameters = [])
	{
		return new ($this->getTableClass())($parameters);
	}

	public static function searchableName()
	{
		return __('filter.' . strtolower(class_basename(self::class)));
	}

    public function filterable($key): Filterable
	{
		return $this->decoratedFilterables()[$key];
	}

	public function decoratedFilterables()
	{
		return collect(self::filterables())->map(function ($filterable) {
			return $filterable->injectContext($this->searchContextService);
		});
	}


	public function decoratedSections()
	{
		return collect(self::sections())->map(function ($section) {
			return $section->injectContext($this->searchContextService);
		});
	}

	public function getInitialRules()
	{
		$state = searchService()->getStore()->getState();

		$rules = collect($state->getSearchableInstance()?->getDefaultRulesApplied() ?? []);

		if($state->getSearch()) {
			/**
			 * @var \Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn $filterable
			 */
			$filterable = $this->filterable(self::getBaseFilterable());

			if (!($filterable instanceof \Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn)) {
				throw new \Exception('The base filterable must be a FilterableColumn');
			}

			$defaultSearchRule = ($filterable->getRuleInstance([
				'value' => $state->getSearch(),
			]))->setKeyReference(self::getBaseFilterable())->injectContext($this->searchContextService);

			$rules->push($defaultSearchRule);
		}

		return $rules;
	}

	public function defaultRulesApplied()
	{
		return [];
	}

	public final function getDefaultRulesApplied()
	{
		return collect($this->defaultRulesApplied())->map(function ($rule) {
			return $rule->injectContext($this->searchContextService);
		});
	}

	public static function baseSearchQuery()
	{
		return self::query();
	}

	public function getEagerRelationsKeys()
	{
		return [];
	}
}