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

        // Only an item's own created() hook: on Eloquent models (searchables) created() is the static "created" event
        // registration, which made the SearchService a listener ("not callable" Error on the next insert of that model).
        if (method_exists($this, 'created') && !(new \ReflectionMethod($this, 'created'))->isStatic()) {
            $this->created($contextService);
        }

        return $this;
    }

    public function getContext()
    {
        return $this->searchContextService;
    }

    /** False before injectContext() (e.g. a freshly unserialized rule): the typed property can't be read then. */
    public function hasContext(): bool
    {
        return isset($this->searchContextService);
    }
}