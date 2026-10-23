<?php

namespace Kompo\Searchbar\SearchItems\Stores;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kompo\Searchbar\Components\NavbarSearchPills;
use Kompo\Searchbar\Facades\SearchStateModel;
use Kompo\Searchbar\Models\SearchStateType;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Rules\PremadeRuleWrapper;
use Kompo\Searchbar\SearchService;

/**
 * A user's last navbar searches: search_states rows of type RECENT, the latest searchbar.recent-searches (8) per user,
 * listed on the navbar panel while nothing is typed or filtered (RecentSearchesList). A search becomes recent when it
 * is used, never while it is typed ("mar", "mart"...): Enter in the navbar and a result opened from the panel (both post
 * searchstate/remember), "Open in a table" (SearchResults). A search with filters and no text counts.
 *
 * The same search (entity, text ignoring case and spaces, rules ignoring their ids and order) is one row: used again,
 * it moves to the top. Its row is found by a token hashing the user id with that signature (the unique token index),
 * then deleted and inserted again: rows are ordered by id (updated_at has whole seconds, ties were common). Each write
 * drops the user's rows beyond the limit; searchbar:prune-links trims every user (a lowered limit, the feature off).
 *
 * The query builder, not the model (as DatabaseStore): no kompo-auth security or model events, and every query filters
 * on the signed-in user. A row is restored as a link is opened: only the user's own RECENT row (a crafted id, a
 * favorite's or a link's, changes nothing), of a registered entity, cleaned as a reopened state. Stored as favorites
 * are (SearchState::toArray(), v2 JSON), plus what the list shows without reading a rule (filtersCount, filters).
 */
class RecentSearches
{
    /** Filters described in a row (a filters-only search is shown by them). */
    const MAX_DESCRIBED = 5;

    /** Recent searches kept per user (searchbar.recent-searches); 0 turns the feature off. */
    public static function limit(): int
    {
        return max(0, (int) config('searchbar.recent-searches', 8));
    }

    /** On for the signed-in user: nothing is recorded, listed or restored for a guest or with a limit of 0. */
    public static function enabled(): bool
    {
        return static::limit() > 0 && auth()->check();
    }

    /**
     * Records $state as the user's latest search. $search: the text used (posted with searchstate/remember: the state
     * may already hold newer text), else the state's. Only when its text or a filter (pending pills aside) says
     * something. Never throws (a failed record must not fail a search or a page). Returns whether a row was written.
     */
    public static function remember(SearchState $state, ?string $search = null): bool
    {
        $userId = auth()->id();

        if (!$userId || !static::limit()) {
            return false;
        }

        try {
            $text = trim((string) SearchService::capSearchText($search ?? (string) $state->getSearch()));
            $rules = $state->getRules()->reject(fn($r) => $r instanceof FilterableRule && $r->isPendingValue())->values();
            $filters = $rules->filter(fn($r) => $r instanceof FilterableRule)->values();

            if ($text === '' && $filters->isEmpty()) {
                return false;
            }

            $entity = (string) $state->getSearchableEntity();
            $signature = static::signature($entity, $text, $rules);

            // As a favorite (no pending pill, no open editor, no header sort), with the text used, and what the list
            // shows without decoding a rule: the count the closed navbar's chip shows, the filters as plain text.
            $raw = StateCodec::toJson(array_merge($state->toArray(), ['search' => $text]) + [
                'filtersCount' => NavbarSearchPills::appliedFiltersCount($rules),
                'filters' => static::describe($filters),
            ]);

            if (strlen($raw) > StateCodec::maxBytes()) {
                Log::warning('searchbar.state_too_large', ['key' => 'recent', 'user_id' => $userId, 'bytes' => strlen($raw)]);

                return false;
            }

            $token = static::token($userId, $signature);
            $now = now();

            // Used again: its row goes, and comes back as the newest (ids order the rows). A second request recording
            // the same search at the same time inserts nothing (the unique token): that row is the same search.
            static::rows($userId)->where('token', $token)->delete();
            DB::table(static::table())->insertOrIgnore([
                'name' => mb_substr($text, 0, 255),
                'token' => $token,
                'type' => SearchStateType::RECENT->value,
                'user_id' => $userId,
                'added_by' => $userId,
                'modified_by' => $userId,
                'raw_state' => $raw,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);

            static::trim($userId);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The user's recent searches, newest first: objects {id, name (the text, '' for a filters-only search), entity (its
     * label, null without entity), filtersCount, filters (plain text)}. Read without its rules (StateCodec::header());
     * a row of an entity no longer registered, or that isn't a state, is left out.
     */
    public static function forUser(): Collection
    {
        if (!static::enabled()) {
            return collect();
        }

        $searchables = searchService()->getSearchables();

        return static::rows(auth()->id())->orderByDesc('id')->limit(static::limit())->get(['id', 'name', 'raw_state'])
            ->map(function ($row) use ($searchables) {
                $data = SearchStore::decodeRow($row, false);
                $entity = $data['entity'] ?? null;

                if (!$data || !static::isRegistered($entity, $searchables)) {
                    return null;
                }

                return (object) [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'entity' => is_string($entity) && $entity !== '' ? (string) $entity::searchableName() : null,
                    'filtersCount' => is_int($data['filtersCount'] ?? null) ? $data['filtersCount'] : 0,
                    'filters' => collect(is_array($data['filters'] ?? null) ? $data['filters'] : [])
                        ->filter(fn($line) => is_string($line) && $line !== '')->values()->all(),
                ];
            })->filter()->values();
    }

    /**
     * Puts the user's recent search $id into $service's state (the navbar's), open, as a link is opened: cleaned as a
     * reopened state (no pending pill or open editor, no filter the entity no longer declares, premade rules rebuilt
     * for the viewer's current team; a rule that no longer fits its filter is dropped, logged). Used again, it moves to
     * the top. A row that isn't a state or of an entity no longer registered is forgotten, the navbar keeps its search.
     * Returns whether the state was replaced.
     */
    public static function restore($id, SearchService $service): bool
    {
        $row = static::find($id);

        if (!$row) {
            return false;
        }

        $data = SearchStore::decodeRow($row);
        $state = $data && static::isRegistered($data['entity'] ?? null, searchService()->getSearchables())
            ? rescue(fn() => $service->getStore()->stateFromArray($data), null, true)
            : null;

        if (!$state) {
            Log::warning('searchbar.recent_undecodable', ['recent' => $row->id]);
            static::forget($row->id);

            return false;
        }

        $state->cleanForReopen($state->getSearchableInstance());
        $state->setOpen(true);
        // In place of the navbar's state, under its lock as any change (SearchStore::mutate()).
        $service->getStore()->mutate(fn() => $state);

        // Recorded again as it now is (a dropped filter makes it another search): the old row goes first.
        static::forget($row->id);
        static::remember($state);

        return true;
    }

    /** Removes one of the user's recent searches (any other id: nothing). */
    public static function forget($id): void
    {
        if (auth()->id() && static::isId($id)) {
            static::rows(auth()->id())->where('id', (int) $id)->delete();
        }
    }

    /** Removes all the user's recent searches. */
    public static function clear(): void
    {
        if (auth()->id()) {
            static::rows(auth()->id())->delete();
        }
    }

    /**
     * Keeps each user's latest limit() recent searches (none when the feature is off) and deletes the rest; rows
     * without a user too. Only RECENT rows: links and remembered tables have their own lifetimes. Returns how many.
     * Run daily by searchbar:prune-links (each write already trims its user: this catches a lowered limit).
     */
    public static function prune(): int
    {
        $deleted = DB::table(static::table())->where('type', SearchStateType::RECENT->value)->whereNull('user_id')->delete();

        $users = DB::table(static::table())->where('type', SearchStateType::RECENT->value)->whereNotNull('user_id')
            ->groupBy('user_id')->havingRaw('COUNT(*) > ?', [static::limit()])->pluck('user_id');

        return $deleted + $users->sum(fn($userId) => static::trim($userId));
    }

    /** The user's row $id, if it is one of their recent searches (none while they are off). */
    protected static function find($id): ?object
    {
        return static::enabled() && static::isId($id) ? static::rows(auth()->id())->where('id', (int) $id)->first() : null;
    }

    /**
     * Deletes the user's rows beyond limit(), the oldest first. Returns how many. Below a cutoff id (the limit()-th
     * newest), not "every id but those read": a row another request inserted meanwhile (a click right after Enter's
     * record, the daily prune) is newer than the cutoff and stays.
     */
    protected static function trim($userId): int
    {
        if (!static::limit()) {
            return static::rows($userId)->delete();
        }

        $cutoff = static::rows($userId)->orderByDesc('id')->skip(static::limit() - 1)->value('id');

        return $cutoff ? static::rows($userId)->where('id', '<', $cutoff)->delete() : 0;
    }

    /** The user's RECENT rows. Hard deletes: the model soft deletes, these rows have no other use (as pruned links). */
    protected static function rows($userId)
    {
        return DB::table(static::table())->where('type', SearchStateType::RECENT->value)->where('user_id', $userId);
    }

    /**
     * What makes two searches the same: the entity, the text (case and spaces aside), each rule by its class, filter
     * key, operator, value and params (a premade rule by its key), whatever their ids and order.
     */
    protected static function signature(string $entity, string $text, Collection $rules): string
    {
        $plain = function ($value) use (&$plain) {
            return match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \UnitEnum => $value->name,
                is_array($value) => array_map($plain, $value),
                is_object($value) => get_class($value),
                default => $value,
            };
        };
        $json = fn($value) => json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $rules = $rules->map(fn($rule) => $json(match (true) {
            $rule instanceof PremadeRuleWrapper => ['premade', $rule->getKey()],
            $rule instanceof FilterableRule => [
                get_class($rule),
                $rule->getKeyReference(),
                method_exists($rule, 'getOperator') ? $plain($rule->getOperator()) : null,
                method_exists($rule, 'getValue') ? $plain($rule->getValue()) : null,
                method_exists($rule, 'getParams') ? $plain($rule->getParams()) : null,
            ],
            default => [get_class($rule), $plain($rule->toArray())],
        }))->sort()->values()->all();

        return hash('sha256', $json([$entity, mb_strtolower((string) preg_replace('/\s+/u', ' ', $text)), $rules]));
    }

    /**
     * The filters as plain text ("Email: contains gmail"), at most MAX_DESCRIBED, each cut at 80 characters. In the
     * language of the moment the search was used (the list decodes no rule to translate them again): a user who then
     * switches language sees these lines as they were, the entity and the count translated (README "Recent searches").
     */
    protected static function describe(Collection $filters): array
    {
        return $filters->map(fn($rule) => rescue(fn() => $rule->describe(), null, false))
            ->filter(fn($line) => is_string($line) && trim($line) !== '')
            ->take(static::MAX_DESCRIBED)
            ->map(fn($line) => mb_strimwidth(trim($line), 0, 80, '…'))
            ->values()->all();
    }

    /** 64 hex characters (the token column): the user id is part of it, so users never share a row. */
    protected static function token($userId, string $signature): string
    {
        return hash('sha256', 'recent|' . $userId . '|' . $signature);
    }

    /** No entity (the default results entity), or a registered searchable: the class is instantiated when restored. */
    protected static function isRegistered($entity, Collection $searchables): bool
    {
        return $entity === null || $entity === '' || (is_string($entity) && $searchables->contains($entity));
    }

    protected static function isId($id): bool
    {
        return is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0;
    }

    protected static function table(): string
    {
        return (new (SearchStateModel::getClass()))->getTable();
    }
}
