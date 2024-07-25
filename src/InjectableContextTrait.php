<?php

namespace Kompo\Searchbar;

trait InjectableContextTrait
{
    protected SearchService $searchContextService;

    public function injectContext($contextService)
    {
        $this->searchContextService = $contextService;

        if(method_exists($this, 'created')) {
            $this->created($contextService);
        }

        return $this;
    }
}