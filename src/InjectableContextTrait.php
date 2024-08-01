<?php

namespace Kompo\Searchbar;

trait InjectableContextTrait
{
    protected SearchService $searchContextService;

    public static function createWithContext($context, ...$params)
    {
        return (new static(...$params))->injectContext($context);
    }

    public function injectContext($contextService)
    {
        $this->searchContextService = $contextService;

        if(method_exists($this, 'created')) {
            $this->created($contextService);
        }

        return $this;
    }
}