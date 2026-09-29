<?php

namespace Kompo\Searchbar\SearchItems\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Cache;

/**
 * Full-text search of a rule's column(s): MATCH ... AGAINST (BOOLEAN MODE) on word prefixes ("martin*").
 *
 * Words the FULLTEXT index can't hold are LIKE conditions: shorter than searchbar.fulltext.min-token (a shorter prefix
 * matches most of the index) or longer than the server's innodb_ft_max_token_size (10 on SISC's server: MATCH silently
 * dropped such a word, "+martin* +archambault*" counted every Martin and "archambault" alone found nobody).
 *
 * Two semantics:
 * - ranked (navbar, tables without their own order): any word filters (a typo in one word doesn't empty the results);
 *   the rows with every word come first, then the boolean score, then the model's tie-break;
 * - strict (a query sorted by its own orders or by a header sort, SearchService::isStrictFullText()): every word is
 *   required and the query's order is kept (the relevance orders came after it: every "Martin" interleaved by date).
 *   A relation column (fullTextFilterQuery()) is always strict: a filter inside the relation's EXISTS.
 */
trait FullTextSearchRuleUtils
{
    protected function fullSearchQuery($query)
    {
        $words = $this->fullTextWords($this->value);

        if (!$words) {
            return $query;
        }

        $base = $this->fullTextBaseBuilder($query);
        $columns = $this->qualifiedFullTextColumns($base);

        if (!$columns) {
            return $query;
        }

        $match = 'MATCH(' . implode(', ', $columns) . ') AGAINST(? IN BOOLEAN MODE)';
        $text = count($columns) > 1 ? "CONCAT_WS(' ', " . implode(', ', $columns) . ')' : $columns[0];
        $indexed = $this->indexedFullTextWords($words, $base);
        [$everyWord, $everyWordBindings] = $this->everyWordCondition($match, $text, $words, $indexed);
        $strict = $this->strictFullText();

        // One word, or no indexed word: the filter below already requires every word.
        $severalWords = $indexed && count($words) > 1;

        // The index picks the rows: any indexed word...
        if ($indexed) {
            $query->whereRaw($match, [$this->booleanWords($indexed)]);
        }

        // ...every word in a sorted table (its order can't show the rows with every word first), and when the index
        // holds none of the words (LIKE only).
        if (!$indexed || ($strict && $severalWords)) {
            $query->whereRaw($everyWord, $everyWordBindings);
        }

        // Only a filter: a strict search keeps the query's order; a second full-text rule doesn't select the relevance
        // again (1064); grouped rows have no per-row score (ONLY_FULL_GROUP_BY).
        if ($strict || $this->selectsRelevance($query) || $base->groups || $base->havings || $base->unions) {
            return $query;
        }

        // table.*, not *: a joined table's id replaced the model's. Columns the query selects (withCount,
        // withAggregate, select) are kept: "*" after them was invalid SQL (1064).
        if ($base->columns === null) {
            $table = $this->fullTextTableName($base);
            $query->select($table ? $table . '.*' : '*');
        }

        // Rows with every word first, then the boolean score (it counts prefixes and weighs rare words more; the
        // natural-language score ignored prefixes: "Martin tail" ranked every Martin like Martin Tailleur). Ordered by
        // select aliases, never by bound ORDER BYs: Kompo's header sort empties the orders but not their bindings (HY093).
        $query->selectRaw(($indexed ? $match : '0') . ' AS relevance', $indexed ? [$this->booleanWords($indexed)] : [])
            ->selectRaw(($severalWords ? $everyWord : '1') . ' AS relevance_all', $severalWords ? $everyWordBindings : [])
            ->orderByDesc('relevance_all')
            ->orderByDesc('relevance');

        // Every exact match shares one relevance, so the tie-break is the order users see; a model can define its own.
        return method_exists($query, 'getModel') && $query->getModel()->hasNamedScope('orderSearchResults')
            ? $query->orderSearchResults()
            : $query->orderByRaw('LENGTH(' . $text . ') ASC');
    }

    /**
     * Every typed word required in $column ("table.column"), a filter only: no relevance column, no order. For a
     * relation's column, inside its whereHas / whereDoesntHave (the listed rows aren't the related ones, nothing to
     * rank). The caller checks there is a word (fullTextWords()) and an index (hasFullTextIndex()).
     */
    protected function fullTextFilterQuery($query, $column)
    {
        $words = $this->fullTextWords($this->value);

        if (!$words) {
            return $query;
        }

        $base = $this->fullTextBaseBuilder($query);
        $text = $base->getGrammar()->wrap($column);
        $match = 'MATCH(' . $text . ') AGAINST(? IN BOOLEAN MODE)';
        $indexed = $this->indexedFullTextWords($words, $base);

        // An index holding words up to 84 letters (the server maximum) has the prefixes of every word: it requires
        // each indexed word itself ("rue dufferin" on SISC's 349k addresses: 24 ms, instead of 430 ms fetching every
        // "rue" to check the other word), LIKE the others.
        if ($indexed && $this->fullTextIndexHoldsWholeWords($base)) {
            $query->whereRaw($match, [$this->booleanWords($indexed, true)]);

            foreach (array_diff($words, $indexed) as $word) {
                $query->whereRaw(...$this->wordStartCondition($text, $word));
            }

            return $query;
        }

        // A smaller one (10 on SISC's server) misses the prefixes of longer words: "maisonneu" found 1 person through
        // it, "maisonneuve" 181, and "does not contain maisonneu" kept the people living on Maisonneuve. No index
        // pre-filter then: every word is checked by MATCH or by LIKE word starts, about the cost of the former LIKE
        // (0.5 s on SISC's 349k addresses) until the server holds whole words.
        [$everyWord, $everyWordBindings] = $this->everyWordCondition($match, $text, $words, $indexed);

        return $query->whereRaw($everyWord, $everyWordBindings);
    }

    /**
     * $columns of $table have a FULLTEXT index of their own (MATCH needs one with exactly these columns, else error
     * 1191). Read once per process: SHOW INDEX costs under 1 ms, and a cached answer would keep LIKE for its lifetime
     * after the index is added (the host's migration can run after the code is deployed), or MATCH after it is
     * dropped.
     */
    protected static function hasFullTextIndex($connection, string $table, array $columns): bool
    {
        static $indexes = [];

        if (!in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }

        $key = $connection->getName() . '.' . $table;

        // [index name => its lowercased columns]; unreadable: none (LIKE finds the same rows, slower).
        $indexes[$key] ??= rescue(fn() => collect($connection->select('SHOW INDEX FROM ' . $connection->getQueryGrammar()->wrapTable($table) . " WHERE Index_type = 'FULLTEXT'"))
            ->groupBy(fn($row) => $row->Key_name ?? $row->key_name ?? '')
            ->map(fn($rows) => $rows->map(fn($row) => strtolower($row->Column_name ?? $row->column_name ?? ''))->sort()->values()->all())
            ->all(), [], false);

        $wanted = collect($columns)->map(fn($column) => strtolower($column))->sort()->values()->all();

        return in_array($wanted, $indexes[$key], true);
    }

    /**
     * "The row has every word", as SQL and bindings: the index says so (every indexed word, LIKE for the others) or
     * LIKE word starts do. The index misses the prefixes of words it can't hold ("archamb*" matched 2 people while
     * "Archambault" is ~240), LIKE misses words after separators it doesn't list (fullTextWordSeparators()).
     */
    protected function everyWordCondition(string $match, string $text, array $words, array $indexed): array
    {
        $byLike = $likeBindings = $othersSql = $othersBindings = [];

        foreach ($words as $word) {
            [$sql, $bindings] = $this->wordStartCondition($text, $word);

            $byLike[] = $sql;
            $likeBindings = array_merge($likeBindings, $bindings);

            if (!in_array($word, $indexed, true)) {
                $othersSql[] = $sql;
                $othersBindings = array_merge($othersBindings, $bindings);
            }
        }

        $allLike = '(' . implode(' AND ', $byLike) . ')';

        if (!$indexed) {
            return [$allLike, $likeBindings];
        }

        return [
            '((' . implode(' AND ', array_merge([$match], $othersSql)) . ') OR ' . $allLike . ')',
            array_merge([$this->booleanWords($indexed, true)], $othersBindings, $likeBindings),
        ];
    }

    /**
     * $text has a word starting with $word (at its start or after a separator): the LIKE twin of MATCH's "word*".
     * The filter and relevance_all share it, so "every word" means the same in both. Words are letters and digits
     * only, the separators hold no LIKE wildcard: nothing to escape. The column collation makes it case and accent
     * insensitive. The leading "%word%" rejects most rows in one pass: 11 patterns behind it cost less than 3 alone.
     */
    protected function wordStartCondition(string $text, string $word): array
    {
        $patterns = array_merge(
            ['%' . $word . '%', $word . '%'],
            array_map(fn($separator) => '%' . $separator . $word . '%', $this->fullTextWordSeparators()),
        );

        $starts = implode(' OR ', array_fill(0, count($patterns) - 1, "{$text} LIKE ?"));

        return ["({$text} LIKE ? AND ({$starts}))", $patterns];
    }

    /**
     * The characters a word can follow in the text. MATCH and fullTextWords() split on any symbol, so LIKE must list
     * the ones found before words: with only space and hyphen, "administration" didn't find "Conseil d'administration"
     * and "archambault" missed "L'Archambault" (neither is in the index: longer than its max token).
     */
    protected function fullTextWordSeparators(): array
    {
        return [' ', '-', "'", '’', '(', '.', '/', ',', '"', '«'];
    }

    /** The BOOLEAN MODE value of words: "martin* tremb*" (any of them), "+martin* +tremb*" (all of them). */
    protected function booleanWords(array $words, bool $required = false): string
    {
        return implode(' ', array_map(fn($word) => ($required ? '+' : '') . $this->buildPrefixes($word), $words));
    }

    /**
     * The distinct words of a typed value (letters and digits: symbols split them, "Jean-Marc O'Brien" = Jean, Marc,
     * O, Brien), at most 20. Boolean operators typed by the user are never passed through.
     */
    protected function fullTextWords($value): array
    {
        $words = [];

        foreach (preg_split('/[^\pL\pM\pN]+/u', $this->fullTextText($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $words[mb_strtolower($word)] ??= $word;
        }

        return array_slice(array_values($words), 0, 20);
    }

    /** The words the FULLTEXT index holds (searched with MATCH); the others are LIKE conditions. */
    protected function indexedFullTextWords(array $words, $base): array
    {
        // ngram: every word is searched as a quoted phrase of its n-grams.
        if ($this->usesNgramSearch()) {
            return $words;
        }

        [$min, $max] = static::fullTextTokenSizes($base->getConnection());

        return array_values(array_filter($words, fn($word) => mb_strlen($word) >= $min && mb_strlen($word) <= $max));
    }

    /**
     * The index holds every word typed in full or as a prefix: ngram, or InnoDB words up to 84 letters (the maximum
     * innodb_ft_max_token_size, and its default). Below, a prefix of a longer word finds nothing through the index.
     */
    protected function fullTextIndexHoldsWholeWords($base): bool
    {
        return $this->usesNgramSearch() || static::fullTextTokenSizes($base->getConnection())[1] >= 84;
    }

    /**
     * [min, max] length of a word searched with MATCH. min: searchbar.fulltext.min-token (3), at least the server's
     * innodb_ft_min_token_size. max: searchbar.fulltext.max-token, else the server's innodb_ft_max_token_size.
     */
    protected static function fullTextTokenSizes($connection): array
    {
        [$serverMin, $serverMax] = static::serverFullTextTokenSizes($connection);

        return [
            max((int) config('searchbar.fulltext.min-token', 3), $serverMin),
            (int) (config('searchbar.fulltext.max-token') ?? $serverMax),
        ];
    }

    /** The server's InnoDB token sizes, read once a day per connection (84 = the MySQL/MariaDB default max). */
    protected static function serverFullTextTokenSizes($connection): array
    {
        static $sizes = [];

        $read = function () use ($connection) {
            // Laravel 11 names MariaDB connections "mariadb": skipping them sent 11-84 letter words back to MATCH,
            // which silently drops them on a server holding 10.
            if (!in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                return [0, 84];
            }

            $vars = collect($connection->select("SHOW VARIABLES LIKE 'innodb_ft_%_token_size'"))
                ->mapWithKeys(fn($row) => [strtolower($row->Variable_name ?? $row->variable_name ?? '') => (int) ($row->Value ?? $row->value ?? 0)]);

            return [$vars['innodb_ft_min_token_size'] ?? 0, $vars['innodb_ft_max_token_size'] ?? 84];
        };

        return $sizes[$connection->getName()] ??= rescue(
            fn() => Cache::remember('searchbar.fulltext-token-sizes.' . $connection->getName(), 86400, $read),
            fn() => rescue($read, [0, 84], false),
            false,
        );
    }

    /** Every word required (a sorted table): the rule's search service is applying the rules of such a query. */
    protected function strictFullText(): bool
    {
        return method_exists($this, 'hasContext') && $this->hasContext()
            && method_exists($this->getContext(), 'isStrictFullText') && $this->getContext()->isStrictFullText();
    }

    protected function selectsRelevance($query): bool
    {
        $base = $this->fullTextBaseBuilder($query);

        return collect($base->columns)->contains(
            fn ($col) => $col instanceof Expression && str_contains($col->getValue($base->getGrammar()), ' AS relevance')
        );
    }

    protected function fullTextBaseBuilder($query): QueryBuilder
    {
        return match (true) {
            $query instanceof Relation => $query->getBaseQuery(),
            $query instanceof EloquentBuilder => $query->getQuery(),
            default => $query,
        };
    }

    /** The table (or its alias) the query selects from; null for a subquery or raw from. */
    protected function fullTextTableName(QueryBuilder $base): ?string
    {
        if (!is_string($base->from) || trim($base->from) === '') {
            return null;
        }

        $parts = preg_split('/\s+as\s+/i', trim($base->from));

        return trim(end($parts)) ?: null;
    }

    /** The rule's MATCH columns as stored (plain names, "table.column", Expression or "RAW::" SQL). */
    protected function fullTextColumns(): array
    {
        return [$this->column];
    }

    /**
     * The MATCH columns, wrapped and qualified by the query's table (a joined table with the same column made them
     * ambiguous): "name_ft" = `persons`.`name_ft`. Raw SQL (Expression, "RAW::") is kept as written; a MATCH takes a
     * list of columns, so its commas separate columns.
     */
    protected function qualifiedFullTextColumns(QueryBuilder $base): array
    {
        $grammar = $base->getGrammar();
        $table = $this->fullTextTableName($base);

        return collect($this->fullTextColumns())->flatMap(function ($column) use ($grammar, $table) {
            if ($column instanceof Expression || str_starts_with((string) $column, 'RAW::')) {
                $raw = $column instanceof Expression ? (string) $column->getValue($grammar) : substr($column, 5);

                return array_map('trim', explode(',', $raw));
            }

            return collect(explode(',', (string) $column))->map(fn($name) => trim($name))->filter()->map(fn($name) => match (true) {
                (bool) preg_match('/^\w+$/', $name) => $grammar->wrap($table ? $table . '.' . $name : $name),
                (bool) preg_match('/^\w+\.\w+$/', $name) => $grammar->wrap($name),
                default => $name,
            });
        })->filter(fn($column) => $column !== '')->values()->all();
    }

    /** The MATCH column list as the rule stores it (kept for callers of the former API). */
    protected function getColumnForFullTextSearch()
    {
        return collect($this->fullTextColumns())
            ->map(fn($column) => $column instanceof Expression ? $column->getValue(\DB::getQueryGrammar()) : $column)
            ->implode(', ');
    }

    protected function constructValueForFullTextSearch($value)
    {
        return implode(' ', $this->fullTextTerms($value));
    }

    /** The BOOLEAN MODE terms of a typed value (word*, "quoted-part"...), at most 20. Kept for external callers. */
    protected function fullTextTerms($value): array
    {
        $value = $this->fullTextText($value);

        return $value === '' ? [] : $this->fullTextTermsFlexible($value);
    }

    /** A rule value as text: states stored before per-operator values (sessions, favorites) can hold lists. */
    protected function fullTextText($value): string
    {
        if (is_array($value)) {
            return collect($value)->flatten()->filter(fn($v) => is_scalar($v) && $v !== '')->implode(' ');
        }

        return is_scalar($value) ? (string) $value : '';
    }

    protected function usesFullTextSearch()
    {
        return $this->getFilterable() && method_exists($this->getFilterable(), 'hasFullTextSearch') &&
            $this->getFilterable()->hasFullTextSearch() &&
            (!property_exists($this, 'operator') || $this->operator->acceptFullTextSearch());
    }

    protected function usesNgramSearch()
    {
        return $this->getFilterable() && method_exists($this->getFilterable(), 'usesNgramSearch') &&
            $this->getFilterable()->usesNgramSearch();
    }

    protected function constructValueForFullTextSearchFlexible(?string $value, int $minLen = 3): string
    {
        return $value === null ? '' : implode(' ', $this->fullTextTermsFlexible($value, $minLen));
    }

    protected function fullTextTermsFlexible(string $value, int $minLen = 3): array
    {
        // Normalize spaces
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($value === '') return [];

        $tokens = preg_split('/\s+/u', $value);
        $out = [];

        foreach ($tokens as $word) {
            if ($word === '') continue;

            // Remove quotes to avoid breaking the subsequent quoting
            $w = str_replace(['"', '“', '”', '’', "'"], ' ', $word);
            $w = trim($w);

            // Remove dangling operators that the parser interprets (start/end of word)
            $w = ltrim($w, "+-~<>(@)");
            $w = rtrim($w, "*).,;:!?");

            if ($w === '') continue;

            // Pure alphanumeric token (no symbols). Strategy "precision-first":
            if (preg_match('/^[\pL\pN]+$/u', $w)) {
                // Respect minimum length for FT
                if (mb_strlen($w) >= $minLen) {
                    // Prefix to expand coverage (autocomplete)
                    $out[] = $this->buildPrefixes($w);
                }
                continue;
            }

            // Token with symbols (e.g. test-, e-mail, c++). Strategy "recall-first":

            // a) Quoted version (without wildcard). Useful if the parser doesn't cut it completely.
            //    Not strictly necessary; if you want ultra-simple, you can omit it.
            $quoted = trim($w);
            if ($quoted !== '') {
                // Remove quotes inside
                $quoted = str_replace('"', ' ', $quoted);
                // Literal phrase (doesn't break because we already cleaned dangling operators)
                $out[] = "\"{$quoted}\"";
            }

            // b) Break into parts by non-alphanumeric characters and add wildcards to each part.
            $parts = preg_split('/[^\pL\pN]+/u', $w, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $p) {
                if (mb_strlen($p) >= $minLen) {
                    $out[] = $this->buildPrefixes($p);
                }
            }
        }

        // Limit the number of terms to avoid huge queries
        return array_slice($out, 0, 20);
    }

    protected function buildPrefixes($word)
    {
        if ($this->usesNgramSearch()) {
            return '"' . $word . '"';
        } else {
            return $word . '*';
        }
    }
}
