<?php

namespace Kompo\Searchbar\Components;

use Illuminate\Support\Facades\Log;

/**
 * Explicit permission of a table's grouped actions (bulk delete, host actions), on top of the model's own security.
 * That security (kompo-auth HasSecurity) is fail-open on an unseeded permission key; this check is kompo-auth's
 * explicit one (checkAuthPermission, WRITE): fail-closed on an unseeded key, true for super admins and under the
 * global bypass. No team is given: WRITE in any of the user's teams passes, then each row's own security checks that
 * row's team when the action writes it.
 *
 * Who decides, first match:
 *  1. config('searchbar.grouped-actions-gate'): an invokable class, __invoke($searchable, string $action): bool (a
 *     class string, not a closure: it survives config:cache);
 *  2. the searchable's groupedActionPermission(string $action): a permission key (string), null (no explicit check:
 *     model security and deletable() only) or false (never);
 *  3. the model's own kompo-auth permission key (the one its HasSecurity uses).
 * Without kompo-auth there is no permission to check: 2's false still refuses, otherwise the model's rules apply.
 */
class GroupedActionGate
{
    public static function allows($searchable, string $action): bool
    {
        // No entity: the state is gone (pruned link), there are no results to act on.
        if (!$searchable) {
            return false;
        }

        if ($gate = config('searchbar.grouped-actions-gate')) {
            return static::hostGate($gate, $searchable, $action);
        }

        $key = method_exists($searchable, 'groupedActionPermission')
            ? $searchable->groupedActionPermission($action)
            : static::defaultPermissionKey($searchable);

        if ($key === null) {
            return true;
        }

        // false, or anything that isn't a key ('' included): closed.
        if (!is_string($key) || $key === '') {
            return false;
        }

        if (!function_exists('checkAuthPermission') || !enum_exists(\Kompo\Auth\Models\Teams\PermissionTypeEnum::class)) {
            return true;
        }

        return checkAuthPermission($key, \Kompo\Auth\Models\Teams\PermissionTypeEnum::WRITE);
    }

    /** The key the model's own security uses: HasPermissionKey, else the class basename. */
    public static function defaultPermissionKey($searchable): string
    {
        $registry = \Kompo\Auth\Teams\Security\SecurityMetadataRegistry::class;
        $key = class_exists($registry) ? (string) ($registry::for(get_class($searchable))['permissionKey'] ?? '') : '';

        return $key !== '' ? $key : class_basename($searchable);
    }

    /** A misconfigured gate refuses (and says so in the log): the host meant to replace the check, not to drop it. */
    protected static function hostGate($gate, $searchable, string $action): bool
    {
        if (is_string($gate) && class_exists($gate) && is_callable($instance = app($gate))) {
            return (bool) $instance($searchable, $action);
        }

        if (is_callable($gate)) {
            return (bool) $gate($searchable, $action);
        }

        Log::warning('searchbar.grouped_actions_gate_invalid', ['gate' => is_string($gate) ? $gate : get_debug_type($gate)]);

        return false;
    }
}
