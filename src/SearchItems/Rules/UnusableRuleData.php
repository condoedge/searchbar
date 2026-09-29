<?php

namespace Kompo\Searchbar\SearchItems\Rules;

/**
 * Stored rule data that can't be rebuilt into a rule of the filter as it is declared now (Filterable::ruleFromData()):
 * its message is the reason (invalid_operator, invalid_scope, invalid_columns...), $detail what was stored (the scope,
 * the operator: never a value the user typed). The rule is dropped from the state and logged (StateCodec).
 */
class UnusableRuleData extends \InvalidArgumentException
{
    public function __construct(string $reason, public readonly ?string $detail = null)
    {
        parent::__construct($reason);
    }

    /** What was stored, as a short text for the logs and searchbar:migrate-states. */
    public static function describe($stored): string
    {
        return mb_strimwidth(is_scalar($stored) || $stored === null ? var_export($stored, true) : json_encode($stored), 0, 80, '…');
    }
}
