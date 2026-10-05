<?php

namespace Kompo\Searchbar\Components\RuleForm;

use Illuminate\Validation\Rule;
use Kompo\Searchbar\SearchItems\Filterables\Filterable;
use Kompo\Searchbar\SearchItems\Filterables\FilterableSelectScope;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;

class ScopeSelectRuleForm extends AbstractRuleForm
{
    public function constructRuleFromRequest(Filterable $colSpec): FilterableRule
    {
        $scope = request('scope');

        return $colSpec->getRuleInstance(compact('scope'));
    }

    /**
     * One of the filter's scopes: without one (nothing picked, or a scope it doesn't declare) a pill of no scope was
     * stored, then dropped (and logged) on every read of the state (StateCodec).
     */
    public function rules()
    {
        $filterable = $this->searchableInstance?->filterable((string) $this->key);
        $scopes = $filterable instanceof FilterableSelectScope ? $filterable->optionsScopes()->keys()->all() : [];

        return [
            'scope' => ['required', Rule::in($scopes)],
        ];
    }

    public function render()
    {
        /**
         * @var \Kompo\Searchbar\SearchItems\Filterables\FilterableSelectScope $colSpec
         */
        $colSpec = $this->searchableInstance->filterable($this->key);

        return _Rows(
            _Select()->name('scope')->options($colSpec->optionsScopes()->toArray())->overModal('scope' . \Str::random(5) . time()),

            _FlexEnd(
                _SubmitButton('generic.save')->onSuccess(fn($e) => $e->refresh($this->refreshAfterSave())->closeModal()),
            ),
        );
    }
}
