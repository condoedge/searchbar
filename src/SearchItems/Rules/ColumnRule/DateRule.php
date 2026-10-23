<?php

namespace Kompo\Searchbar\SearchItems\Rules\ColumnRule;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\FilterableColumnTypeEnum;
use Kompo\Searchbar\SearchItems\Filterables\FilterableColumn\OperatorEnum;

/**
 * Dates compare on their day, whatever time a DATETIME / TIMESTAMP column holds, through day bounds on the bare
 * column (">= day" and "< next day") rather than DATE_FORMAT(column): a function on the column hid its index (a
 * full scan of persons for one birth date), and a raw Expression column couldn't be interpolated into it at all.
 *
 * 'Y-m-d' literals compare right with DATE, DATETIME and TIMESTAMP columns (the server converts a TIMESTAMP in the
 * session time zone, as DATE_FORMAT did) and with 'Y-m-d…' strings. A value that isn't a date matches nothing:
 * compared as text, "< garbage" matched every row.
 *
 * A 'relation.column' (e.g. 'personTeams.created_at', nested 'personTeams.team.active_at') filters through whereHas
 * on an Eloquent query; a 'table.column' of the query's own or joined tables stays a qualified column.
 */
class DateRule extends ColumnRule
{
    public function decorateQuery($query)
    {
        if ($this->isPendingValue()) {
            return $query;
        }

        if ($relation = $this->relationColumn($query)) {
            return $this->relationQuery($query, ...$relation);
        }

        // A plain column, a RAW:: one or an Expression (FilterableRawColumn): never interpolated into a string.
        return $this->dateQuery($query, $this->getParsedColumn(), $this->operator);
    }

    protected function dateQuery($query, $column, OperatorEnum $operator)
    {
        if ($operator === OperatorEnum::BETWEEN) {
            return $this->betweenQuery($query, $column);
        }

        // IN / NOT IN / CONTAINS aren't offered for dates: a stored one keeps the operator's own query.
        if (!in_array($operator, FilterableColumnTypeEnum::DATE->operatorOptions(), true)) {
            return $operator->constructQuery($query, $column, $this->queryValue());
        }

        if (!$day = $this->valueDay($operator)) {
            return $this->matchNothing($query);
        }

        return match ($operator) {
            OperatorEnum::EQUALS_TO => $this->untilEndOf($query->where($column, '>=', $day), $column, $day),
            // A NULL date stays out, as it did with DATE_FORMAT(NULL) != ?.
            OperatorEnum::DIFFERENT => $query->where(fn ($q) => $q->where($column, '<', $day)
                ->when(static::nextDay($day), fn ($q, $next) => $q->orWhere($column, '>=', $next))),
            OperatorEnum::MORE_THAN => $this->afterEndOf($query, $column, $day),
            OperatorEnum::MORE_THAN_OR_EQUAL => $query->where($column, '>=', $day),
            OperatorEnum::LESS_THAN => $query->where($column, '<', $day),
            OperatorEnum::LESS_THAN_OR_EQUAL => $this->untilEndOf($query, $column, $day),
        };
    }

    /** The day of the value for a one-value operator; null when it isn't a date. */
    protected function valueDay(OperatorEnum $operator): ?string
    {
        return static::day(FilterableColumnTypeEnum::DATE->normalizeValue($this->value, $operator));
    }

    /**
     * Up to the last second of $day: before the next day. 9999-12-31 has no next day ('10000-01-01' isn't a date:
     * "<= 9999-12-31" matched nothing), so it only leaves NULL out.
     */
    protected function untilEndOf($query, $column, string $day)
    {
        $next = static::nextDay($day);

        return $next ? $query->where($column, '<', $next) : $query->whereNotNull($column);
    }

    /** After the last second of $day: from the next day on. */
    protected function afterEndOf($query, $column, string $day)
    {
        $next = static::nextDay($day);

        return $next ? $query->where($column, '>=', $next) : $this->matchNothing($query);
    }

    /**
     * From the first day to the end of the last one; a missing bound leaves that side open and reversed bounds are
     * swapped. A bound that was given but isn't a date matches nothing (not an open side: that widened the range).
     */
    protected function betweenQuery($query, $column)
    {
        // A lone value is that day, as OperatorEnum::BETWEEN reads it. The range picker's "null" bounds are missing.
        $bounds = is_array($this->value) ? array_values($this->value) : [$this->value, $this->value];

        // normalizeValue reads a list bound as missing: it was given, and it isn't a date.
        if (collect(array_slice($bounds, 0, 2))->contains(fn ($bound) => is_array($bound) && $bound)) {
            return $this->matchNothing($query);
        }

        if (!$range = FilterableColumnTypeEnum::DATE->normalizeValue($bounds, OperatorEnum::BETWEEN)) {
            return $query;
        }

        [$from, $to] = $range;
        [$fromDay, $toDay] = [static::day($from), static::day($to)];

        if (($from !== null && !$fromDay) || ($to !== null && !$toDay)) {
            return $this->matchNothing($query);
        }

        if ($fromDay && $toDay && $fromDay > $toDay) {
            [$fromDay, $toDay] = [$toDay, $fromDay];
        }

        return $query->when($fromDay, fn ($q) => $q->where($column, '>=', $fromDay))
            ->when($toDay, fn ($q) => $this->untilEndOf($q, $column, $toDay));
    }

    /**
     * As RelationRuleUtils: a negative operator ("different from") is "no related row matches", so a record without
     * any related row is kept, not "a related row on another day".
     */
    protected function relationQuery($query, string $relation, string $column)
    {
        $negative = $this->operator->negative();

        // Checked out here: inside whereDoesntHave, "matches nothing" would match every row.
        if ($this->operator === OperatorEnum::DIFFERENT && !$this->valueDay($negative)) {
            return $this->matchNothing($query);
        }

        // Qualified inside the closure: the EXISTS of a self relation aliases its table.
        $related = fn ($q) => $this->dateQuery($q, $q->qualifyColumn($column), $negative ?? $this->operator);

        return $negative ? $query->whereDoesntHave($relation, $related) : $query->whereHas($relation, $related);
    }

    /**
     * [relation path, column] when the column is 'relation(.relation…).column' of an Eloquent query's model. Null for
     * a bare, RAW:: or Expression column, and for a 'table.column' of the query's own or joined tables (qualified).
     */
    protected function relationColumn($query): ?array
    {
        $eloquent = $query instanceof Relation ? $query->getQuery() : $query;

        if (!$eloquent instanceof EloquentBuilder || !is_string($this->column) || $this->isRawColumn() || !str_contains($this->column, '.')) {
            return null;
        }

        $segments = explode('.', $this->column);
        $column = array_pop($segments);
        $model = $eloquent->getModel();
        $base = $eloquent->getQuery();

        // The query's tables and aliases ('persons as p', a joinSub's "(select …) as `x`"): a qualified column.
        $tables = collect([$base->from, ...collect($base->joins ?: [])->pluck('table')])
            ->map(fn ($table) => $table instanceof Expression ? $table->getValue($base->getGrammar()) : $table)
            ->filter(fn ($table) => is_string($table))
            ->flatMap(fn ($table) => preg_split('/\s+as\s+/i', $table))
            ->map(fn ($name) => trim($name, " \t\n\r`\"'"))
            ->push($model->getTable());

        if ($tables->contains($segments[0])) {
            return null;
        }

        foreach ($segments as $name) {
            try {
                // Never a Model method (save, delete, touch…): only a relation is called.
                $relation = !method_exists(Model::class, $name) && $model->isRelation($name) ? $model->$name() : null;
            } catch (\Throwable) {
                $relation = null; // a method that isn't a relation (one that needs arguments...)
            }

            if (!$relation instanceof Relation) {
                return null;
            }

            $model = $relation->getRelated();
        }

        return [implode('.', $segments), $column];
    }

    /**
     * The 'Y-m-d' day of a value: a date, a date and time ('2024-05-14 10:00', ISO '2024-05-14T10:00:00.000Z',
     * '…+02:00') or a DateTimeInterface. Null for anything else, text after the date included ('2024-05-14 abc',
     * '2024-05-14 to 2024-12-31'), and for a day that doesn't exist (2023-02-29).
     */
    protected static function day($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $time = '(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?';

        if (!is_string($value) || !preg_match('/^\s*(\d{4}-\d{2}-\d{2})(?:(?:T|\s+)' . $time . ')?\s*$/', $value, $m)) {
            return null;
        }

        // Round trip: createFromFormat overflows 2024-02-30 into March instead of failing.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);

        return $date && $date->format('Y-m-d') === $m[1] ? $m[1] : null;
    }

    /** Null after 9999-12-31: MySQL dates end there. */
    protected static function nextDay(string $day): ?string
    {
        $next = (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');

        return strlen($next) === 10 ? $next : null;
    }

    /** Laravel's own always-false condition (whereIn with no values). */
    protected function matchNothing($query)
    {
        return $query->whereRaw('0 = 1');
    }
}
