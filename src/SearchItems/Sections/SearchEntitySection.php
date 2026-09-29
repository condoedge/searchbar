<?php

namespace Kompo\Searchbar\SearchItems\Sections;

use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumn;

class SearchEntitySection extends SearchSection
{
	protected $filterableClass = FilterableColumn::class;
	protected FilterableColumn $filterable;
	protected string $filterableKey;

    public function __construct($filterableKey)
    {
		$this->filterableKey = $filterableKey;
    }

	public function created()
	{
		$this->filterable = $this->getFilterable($this->filterableKey);
	}

	public function options()
	{
		// Chip labels render as HTML: record names (relations) are escaped.
		return collect($this->filterable->getEntityType()?->optionsWithLabels())->map(fn($label) => is_string($label) ? e($label) : $label);
	}

	public function getRule($type)
	{
		return $this->filterable->getRuleInstance([
            'value' => [$type],
        ])->setKeyReference($this->filterableKey);
	}

	/** The filter key and the option: no serialized rule travels through the browser. */
	protected function chipParams($index): array
	{
		return ['key' => $this->filterableKey, 'option' => $index];
	}

	public function isOptionSelected($index): bool
	{
		return $this->getActiveFilterableRules()->contains(function ($rule) use ($index) {
			return $rule->getKeyReference() === $this->filterableKey
				&& is_array($rule->getValue()) && in_array($index, $rule->getValue());
		});
	}

	protected function getSectionLabel(): ?string
	{
		return $this->filterable->getFilterName();
	}
}