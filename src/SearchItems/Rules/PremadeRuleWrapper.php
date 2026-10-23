<?php

namespace Kompo\Searchbar\SearchItems\Rules;

class PremadeRuleWrapper extends Rule
{
    protected Rule $rule;
    protected string $name;
    protected string $key;
    protected string $description;
    protected bool $removable;
    protected bool $default = false;
    protected bool $inverse = false;

    public function __construct($rule, $key, $name = '', $description = '', $removable = true, $inverse = false, $default = false)
    {
        $this->rule = $rule;
        $this->key = $key;
        $this->name = $name;
        $this->description = $description;
        $this->removable = $removable;
        $this->inverse = $inverse;
        $this->default = $default;
    }

    public function created()
    {
        $this->rule->injectContext($this->searchContextService);
    }

    public function renderContent()
    {
        return _RulePill($this->name);
    }

    public function decorateQuery($query)
    {
        return $this->rule->decorateQuery($query);
    }

    /** Its pill's name ("Active", "In this team"), else its toggle's description. */
    public function describe(): ?string
    {
        $label = trim(strip_tags((string) __((string) ($this->name ?: $this->description))));

        return $label !== '' ? html_entity_decode($label, ENT_QUOTES | ENT_HTML5) : null;
    }

    public function toArray()
    {
        return $this->rule->toArray();
    }

    public static function findByKey($searchable, $key)
    {
        return $searchable->getPremadeRules()->first(function ($defaultRule) use ($key) {
            return $defaultRule->getKey() == $key;
        });
    }

    /**
     * Built the same way as $other: same rule with the same parameters, same labels and flags. A stored copy that isn't
     * (built for another team, before a label changed) is rebuilt (SearchState::refreshPremadeRules()).
     */
    public function sameDefinitionAs(self $other): bool
    {
        return get_class($this->rule) === get_class($other->rule)
            // Loose: a team id stored as a string equals the same id as an int.
            && $this->toArray() == $other->toArray()
            && [$this->name, $this->description, $this->removable, $this->inverse, $this->default]
                === [$other->name, $other->description, $other->removable, $other->inverse, $other->default];
    }

    /** This premade rule as applied among $rules (the state's rules are addressed by id: see SearchState). */
    public function findActiveIn($rules): ?Rule
    {
        return collect($rules)->first(fn($rule) => $rule instanceof static && $rule->getKey() == $this->getKey());
    }

    /** @deprecated positions shift: use findActiveIn() and the rule's id. */
    public function getIndexOnRules($rules)
    {
        return $rules->search(function ($rule) {
            if($rule instanceof static) {
                return $rule->getKey() == $this->getKey();
            }

            return false;
        });
    }

    public function isActive()
    {
        $state = $this->searchContextService->getStore()->getState();

        return $this->findActiveIn($state->getRules()) !== null;
    }

    public function getToggle()
    {
        $active = $this->isActive();
        $value = $this->isInverse() ? !$active : $active;

        // Kompo fields show no loading state: the searchbar lock is the only feedback until the refresh. Its state's
        // keys in the URL: the toggle sits in the navbar's filters column or the custom filters modal.
        return _Toggle($this->getDescription())->name('toggle' . $this->getKey())->value($value)
            ->onChange(fn($e) => $e->run('() => { window.searchbarBusy && searchbarBusy(); }')
                && $e->post('searchstate.toggle-default', $this->searchContextService->stateParams(['key' => $this->getKey()]))->withAllFormValues()
                    ->refresh($this->searchContextService->refreshTargets()));
    }

    // GETTERS AND SETTERS
    public function getName()
    {
        return $this->name;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function isRemovable()
    {
        return $this->removable;
    }

    public function isDefault()
    {
        return $this->default;
    }

    public function isInverse()
    {
        return $this->inverse;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function inverse($inverse = true)
    {
        $this->inverse = $inverse;

        return $this;
    }

    public function removable($removable = true)
    {
        $this->removable = $removable;

        return $this;
    }

    public function default($default = true)
    {
        $this->default = $default;

        return $this;
    }

    public function setRuleName($name)
    {
        $this->name = $name;

        return $this;
    }
    
    public function setRuleDescription($description)
    {
        $this->description = $description;

        return $this;
    }
}