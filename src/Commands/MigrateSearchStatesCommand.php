<?php

namespace Kompo\Searchbar\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kompo\Searchbar\Components\NavbarSearchPills;
use Kompo\Searchbar\Facades\SearchStateModel;
use Kompo\Searchbar\Models\SearchStateType;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;
use Kompo\Searchbar\SearchItems\Stores\LinkStore;
use Kompo\Searchbar\SearchItems\Stores\SearchState;
use Kompo\Searchbar\SearchItems\Stores\StateCodec;
use Kompo\Searchbar\SearchService;

/**
 * Converts the stored search states (search_states rows: favorites, links and shared views, remembered tables,
 * recent searches) to format v2 (StateCodec: plain data, no class names but the entity), each rebuilt through its
 * entity's current filterables: a rule that no longer fits is dropped (reported with its reason), a row that isn't a
 * state any more is left as it is (reported). A row whose rules failed to rebuild (a filterable or the premade rules
 * throwing: the command runs without a signed-in user) stays v1, reported: written without them, they'd be lost. Rows
 * already v2 and soft-deleted rows are skipped. Every store reads v1 too: converting is for the rollback path and to
 * stop reading serialized objects, not required to run.
 *
 * Each converted row's v1 payload is kept in search_states_v1 (migration 2026_09_28_100000). --rollback puts it back
 * while the row still holds the v2 payload written by the conversion; a row changed since, or written as v2 by the
 * application, is written as v1 again from its current state (see toV1()), for a release that reads v1 only. --dry-run
 * reports what would change and writes nothing: run it first.
 *
 * The application keeps writing meanwhile: a row is written only while it holds exactly what was read (compared
 * byte for byte, the row locked), and a kept payload is forgotten only with the write that restores its row. A row
 * changed meanwhile is left as it is and reported: run the command again.
 */
class MigrateSearchStatesCommand extends Command
{
    const BACKUP_TABLE = 'search_states_v1';

    protected $signature = 'searchbar:migrate-states
        {--dry-run : Report what would change, write nothing}
        {--rollback : Put the kept v1 payloads back (rows changed since or written as v2: written as v1 again)}
        {--type=* : Only these types (global, user, link, working, recent)}
        {--id=* : Only these rows}
        {--details=200 : Most rows listed in the details}';

    protected $description = 'Convert the stored search states to format v2 (plain data), or back to v1 with --rollback';

    protected SearchService $service;
    protected array $stats = [];
    protected array $details = [];

    public function handle()
    {
        $types = $this->types();

        if ($types === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (!Schema::hasTable(static::BACKUP_TABLE) && (!$dryRun || $this->option('rollback'))) {
            $this->error('Run the migrations first: the v1 payloads are kept in ' . static::BACKUP_TABLE . '.');

            return self::FAILURE;
        }

        // Its own service: the rules rebuilt here are never the request's or the navbar's. A new report per run (the
        // console keeps one instance of a command).
        $this->service = new SearchService('searchbar-migrate-states');
        $this->stats = [];
        $this->details = [];

        $this->line('searchbar:migrate-states' . ($this->option('rollback') ? ' --rollback' : '')
            . ($dryRun ? ' --dry-run: nothing is written.' : ''));

        $this->option('rollback') ? $this->rollback($types, $dryRun) : $this->convert($types, $dryRun);

        return self::SUCCESS;
    }

    // CONVERSION

    protected function convert(array $types, bool $dryRun): void
    {
        $this->rows($types)->chunkById(200, function ($rows) use ($dryRun) {
            foreach ($rows as $row) {
                $this->convertRow($row, $dryRun);
            }
        });

        $this->report(['rows' => 'Rows', 'v2' => 'Already v2', 'v1' => 'v1', 'converted' => $dryRun ? 'To convert' : 'Converted',
            'undecodable' => 'Undecodable', 'failed' => 'Left v1 (a rule failed)', 'rules' => 'Rules kept',
            'dropped' => 'Rules dropped', 'raced' => 'Changed meanwhile (run again)']);
        $this->reportSoftDeleted($types);
    }

    protected function convertRow(object $row, bool $dryRun): void
    {
        $type = $this->typeName($row);
        $raw = (string) $row->raw_state;
        $this->count($type, 'rows');

        if (static::isV2($raw)) {
            $this->count($type, 'v2');

            return;
        }

        $this->count($type, 'v1');

        try {
            $data = StateCodec::toV2($raw, false);
            $state = $data ? StateCodec::decode($data, $this->service, false) : null;
        } catch (\Throwable $e) {
            // Its searchable failing to build (without a signed-in user...): the row stays v1, still read.
            report($e);
            $this->count($type, 'failed');
            $this->detail('Rows left v1 (a rule failed to rebuild)', $row, 'not converted: ' . class_basename($e));

            return;
        }

        if (!$state) {
            $this->count($type, 'undecodable');
            $this->detail('Undecodable rows (left as they are)', $row, $data ? 'entity refused: ' . ($data['entity'] ?? '?') : 'not a stored state');

            return;
        }

        if ($failed = StateCodec::failedDrops($state->droppedStoredRules())) {
            $this->count($type, 'failed');
            $this->detail('Rows left v1 (a rule failed to rebuild)', $row, 'not converted: ' . static::failedText($failed), $state);

            return;
        }

        $new = StateCodec::toJson($this->v2Payload($row, $state, $data));

        // Never read back over the cap (StateCodec): the row stays v1, still read.
        if (strlen($new) > StateCodec::maxBytes()) {
            $this->count($type, 'undecodable');
            $this->detail('Undecodable rows (left as they are)', $row, 'over max-state-kb as v2: ' . strlen($new) . ' bytes');

            return;
        }

        if (!$dryRun && !$this->writeConverted($row, $raw, $new)) {
            $this->raced($type, $row);

            return;
        }

        $this->count($type, 'converted');
        $this->count($type, 'rules', $state->storedRules()->count());
        $this->countDropped($type, $row, $state);
    }

    /** The row's state as the application writes it now: a working state, or a snapshot (plus a recent search's list data). */
    protected function v2Payload(object $row, SearchState $state, array $data): array
    {
        $payload = StateCodec::encode($state, $this->isSnapshot($row));

        if ((int) $row->type === SearchStateType::RECENT->value) {
            // The count follows the rules kept; the filters' lines stay as recorded (in the user's language then).
            $payload += [
                'filtersCount' => NavbarSearchPills::appliedFiltersCount($state->storedRules()),
                'filters' => is_array($data['filters'] ?? null) ? $data['filters'] : [],
            ];
        }

        return $payload;
    }

    /** The v2 payload, its v1 payload kept, while the row holds what was read; its age unchanged. */
    protected function writeConverted(object $row, string $raw, string $new): bool
    {
        return $this->replaceRaw((int) $row->id, $raw, $new, fn() => DB::table(static::BACKUP_TABLE)->upsert([[
            'search_state_id' => $row->id,
            'raw_state' => $raw,
            'converted_hash' => hash('sha256', $new),
            'created_at' => now(),
        ]], ['search_state_id'], ['raw_state', 'converted_hash', 'created_at']));
    }

    // ROLLBACK

    protected function rollback(array $types, bool $dryRun): void
    {
        $filtered = $this->option('type') || $this->option('id');
        // Rows that have a kept payload: not "written as v2 by the application" below.
        $seen = [];

        // By chunks: each holds kept v1 payloads and their rows' v2.
        DB::table(static::BACKUP_TABLE . ' as b')
            ->leftJoin($this->stateTable() . ' as s', 's.id', '=', 'b.search_state_id')
            ->when($filtered, fn($query) => $query->whereIn('s.type', $types)
                ->when($this->ids(), fn($query, $ids) => $query->whereIn('s.id', $ids)))
            ->select(['b.id as backup_id', 'b.raw_state as v1', 'b.converted_hash', 's.id', 's.name', 's.type', 's.user_id', 's.raw_state'])
            ->chunkById(200, function ($backups) use (&$seen, $dryRun) {
                foreach ($backups as $backup) {
                    if ($backup->id !== null) {
                        $seen[$backup->id] = true;
                    }

                    $this->rollbackRow($backup, $dryRun);
                }
            }, 'b.id', 'backup_id');

        // Written as v2 by the application (created or changed since the conversion), no v1 payload kept.
        $this->rows($types)->where('raw_state', 'like', '{%')->chunkById(200, function ($rows) use ($seen, $dryRun) {
            foreach ($rows as $row) {
                if (!isset($seen[$row->id])) {
                    $type = $this->typeName($row);
                    $this->count($type, 'rows');
                    $this->reencode($row, $type, 'written', $dryRun);
                }
            }
        });

        $this->report(['rows' => 'Rows', 'restored' => $dryRun ? 'To restore' : 'Restored',
            'changed' => 'Changed since (re-encoded)', 'written' => 'Written as v2 (re-encoded)', 'v1' => 'Already v1',
            'failed' => 'Not re-encodable', 'dropped' => 'Rules dropped', 'raced' => 'Changed meanwhile (run again)']);

        if ($orphans = $this->stats['orphans']['rows'] ?? 0) {
            $this->line("Kept payloads of deleted rows" . ($dryRun ? ' (to delete)' : ' (deleted)') . ": {$orphans}");
        }
    }

    /** One kept v1 payload ($backup: with its row's columns, null when the row is gone). */
    protected function rollbackRow(object $backup, bool $dryRun): void
    {
        if ($backup->id === null) {
            $this->count('orphans', 'rows');
            $dryRun || $this->forgetBackup($backup->backup_id);

            return;
        }

        $type = $this->typeName($backup);
        $current = (string) $backup->raw_state;
        $this->count($type, 'rows');

        if (hash('sha256', $current) === $backup->converted_hash) {
            // Still the v2 the conversion wrote: its v1 payload goes back, and is forgotten with that write only.
            if (!$dryRun && !$this->replaceRaw((int) $backup->id, $current, (string) $backup->v1, fn() => $this->forgetBackup($backup->backup_id))) {
                $this->raced($type, $backup);

                return;
            }

            $this->count($type, 'restored');
        } elseif (!static::isV2($current)) {
            // v1 again already (rolled back, or written by a release reading v1 only): only the kept payload goes.
            $this->count($type, 'v1');
            $dryRun || $this->forgetBackup($backup->backup_id);
        } else {
            $this->reencode($backup, $type, 'changed', $dryRun, $backup->backup_id);
        }
    }

    /**
     * A v2 row written as v1 again, from its current state (no kept payload, or the row changed since), the kept
     * payload forgotten with that write. Left as it is when a rule failed to rebuild: as v1 it would be lost.
     */
    protected function reencode(object $row, string $type, string $counter, bool $dryRun, $backupId = null): void
    {
        try {
            [$v1, $state] = $this->toV1($row);
        } catch (\Throwable $e) {
            report($e);
            $this->count($type, 'failed');
            $this->detail('Rows not re-encodable (left as they are)', $row, 'failed: ' . class_basename($e));

            return;
        }

        if ($v1 === null) {
            $this->count($type, 'failed');
            $this->detail('Rows not re-encodable (left as they are)', $row, 'not a state any more');

            return;
        }

        if ($failed = StateCodec::failedDrops($state->droppedStoredRules())) {
            $this->count($type, 'failed');
            $this->detail('Rows not re-encodable (left as they are)', $row, 'not re-encoded: ' . static::failedText($failed), $state);

            return;
        }

        $forget = $backupId ? fn() => $this->forgetBackup($backupId) : null;

        if (!$dryRun && !$this->replaceRaw((int) $row->id, (string) $row->raw_state, $v1, $forget)) {
            $this->raced($type, $row);

            return;
        }

        $this->count($type, $counter);
        $this->countDropped($type, $row, $state, false);
    }

    protected function forgetBackup($backupId): void
    {
        DB::table(static::BACKUP_TABLE)->where('id', $backupId)->delete();
    }

    /**
     * [v1 payload, state] of a v2 row, as the release before v2 wrote it: its rules serialized one by one (their search
     * service too, as then), 'sort' for working states, a recent search's list data. [null, null] when it isn't a state.
     *
     * Premade rules are built without a signed-in user (v1 froze them with their parameters): SISC's
     * forTeam(currentTeamId()) gets no team. The release before v2 rebuilds them for the viewer where it reopens a
     * state (remembered tables, shared views, recent searches), not for a navbar favorite or a results link. Signing
     * each owner in instead would run kompo-auth's team reset on owners without a valid team (it writes the user).
     */
    protected function toV1(object $row): array
    {
        $data = StateCodec::toV2((string) $row->raw_state, false);
        $state = $data ? StateCodec::decode($data, $this->service, false) : null;

        if (!$state) {
            return [null, null];
        }

        $snapshot = $this->isSnapshot($row);
        $rules = $state->storedRules()
            ->reject(fn($rule) => $snapshot && $rule instanceof FilterableRule && $rule->isPendingValue())
            ->map(fn($rule) => serialize($snapshot && $rule instanceof FilterableRule ? (clone $rule)->setEditing(false) : $rule))
            ->values()->all();

        $v1 = ['searchableEntity' => $state->getSearchableEntity(), 'search' => $state->getSearch(), 'rules' => $rules]
            + ($snapshot ? [] : ['sort' => $state->getSort()]);

        if ((int) $row->type === SearchStateType::RECENT->value) {
            $v1 += ['filtersCount' => is_int($data['filtersCount'] ?? null) ? $data['filtersCount'] : count($rules),
                'filters' => is_array($data['filters'] ?? null) ? $data['filters'] : []];
        }

        return [serialize($v1), $state];
    }

    // WRITES

    /**
     * Writes $new over row $id only while it holds exactly $expected, then runs $then (the kept payload's upsert or
     * delete), in one transaction with the row locked: the application writes rows meanwhile (a table stored again, a
     * link edited). Compared byte for byte in PHP: SQL's = on raw_state follows the column's collation
     * (utf8mb4_unicode_ci ignores case and trailing spaces), so a newer "MARTIN" would pass for the "martin" read.
     * False when the row changed or is gone: nothing written, the kept payload untouched. Its age is unchanged.
     */
    protected function replaceRaw(int $id, string $expected, string $new, ?callable $then = null): bool
    {
        return DB::transaction(function () use ($id, $expected, $new, $then) {
            $current = DB::table($this->stateTable())->where('id', $id)->lockForUpdate()->value('raw_state');

            if (!is_string($current) || $current !== $expected) {
                return false;
            }

            DB::table($this->stateTable())->where('id', $id)
                ->update(['raw_state' => $new, 'updated_at' => $this->unchangedUpdatedAt()]);

            $then && $then();

            return true;
        });
    }

    // ROWS

    /** Rows of $types (not soft-deleted), by id. */
    protected function rows(array $types)
    {
        return DB::table($this->stateTable())
            ->whereIn('type', $types)
            ->whereNull('deleted_at')
            ->when($this->ids(), fn($query, $ids) => $query->whereIn('id', $ids))
            ->select(['id', 'name', 'type', 'user_id', 'raw_state']);
    }

    /** Favorites, recent searches and shared table views are snapshots; the others are working states. */
    protected function isSnapshot(object $row): bool
    {
        return in_array((int) $row->type, [SearchStateType::GLOBAL->value, SearchStateType::USER->value, SearchStateType::RECENT->value], true)
            || ((int) $row->type === SearchStateType::LINK->value && str_starts_with((string) $row->name, LinkStore::TABLE_PREFIX));
    }

    /** "type_filter: invalid_scope ('hasYoungTeamOccupation')": a dropped rule (StateCodec) in the report. */
    protected static function droppedText(array $dropped): string
    {
        return ($dropped['key'] ?? '?') . ': ' . $dropped['reason']
            . (isset($dropped['detail']) ? " ({$dropped['detail']})" : '') . (isset($dropped['class']) ? " ({$dropped['class']})" : '');
    }

    /** "fragile_filter failed (RuntimeException)": rules that failed to rebuild (StateCodec::failedDrops()). */
    protected static function failedText(array $failed): string
    {
        return collect($failed)->map(fn($rule) => $rule['reason'] === 'no_entity'
            ? ($rule['key'] ?? '?') . ': no searchable to rebuild it'
            : ($rule['key'] ?? '?') . ' failed' . (isset($rule['detail']) ? " ({$rule['detail']})" : ''))->implode(', ');
    }

    protected static function isV2(string $raw): bool
    {
        return str_starts_with(ltrim($raw), '{');
    }

    /** Types asked (--type, by name), else all. Null (after an error) for an unknown one. */
    protected function types(): ?array
    {
        $cases = collect(SearchStateType::cases())->keyBy(fn($case) => strtolower($case->name));
        $asked = collect($this->option('type'))->map(fn($type) => strtolower(trim((string) $type)))->filter();

        if ($unknown = $asked->reject(fn($type) => $cases->has($type))->implode(', ')) {
            $this->error("Unknown type(s): {$unknown}. Types: " . $cases->keys()->implode(', ') . '.');

            return null;
        }

        return ($asked->isEmpty() ? $cases : $cases->only($asked->all()))->map->value->values()->all();
    }

    protected function ids(): array
    {
        return collect($this->option('id'))->filter(fn($id) => ctype_digit((string) $id))->map(fn($id) => (int) $id)->values()->all();
    }

    protected function typeName(object $row): string
    {
        return SearchStateType::tryFrom((int) $row->type)?->name ?? 'type ' . $row->type;
    }

    protected function stateTable(): string
    {
        return (new (SearchStateModel::getClass()))->getTable();
    }

    /** updated_at = updated_at: a row's age (links and tables are pruned by it) isn't reset by a conversion. */
    protected function unchangedUpdatedAt()
    {
        return DB::raw(DB::getQueryGrammar()->wrap('updated_at'));
    }

    // REPORT

    protected function count(string $group, string $counter, int $by = 1): void
    {
        $this->stats[$group][$counter] = ($this->stats[$group][$counter] ?? 0) + $by;
    }

    /** The rules $state dropped (written, or to write), with their reasons ($byReason: in the conversion's summary). */
    protected function countDropped(string $type, object $row, SearchState $state, bool $byReason = true): void
    {
        foreach ($state->droppedStoredRules() as $dropped) {
            $this->count($type, 'dropped');
            $byReason && $this->count('reasons', $dropped['reason']);
            $this->detail('Rules dropped', $row, static::droppedText($dropped), $state);
        }
    }

    protected function raced(string $type, object $row): void
    {
        $this->count($type, 'raced');
        $this->detail('Rows changed meanwhile (left as they are: run again)', $row, 'written by the application while the command ran');
    }

    protected function detail(string $section, object $row, string $text, ?SearchState $state = null): void
    {
        $entity = $state?->getSearchableEntity();
        $this->details[$section][] = sprintf('%s #%d "%s"%s%s: %s', $this->typeName($row), $row->id,
            mb_strimwidth((string) $row->name, 0, 40, '…'), $entity ? ' ' . class_basename($entity) : '',
            $row->user_id ? " (user {$row->user_id})" : '', $text);
    }

    /** The counts per type ($columns: counter => heading), the reasons of the dropped rules, then the details. */
    protected function report(array $columns): void
    {
        $types = collect($this->stats)->except(['reasons', 'orphans']);

        if ($types->isEmpty()) {
            $this->info('No rows.');

            return;
        }

        $this->table(array_merge(['Type'], array_values($columns)), $types->map(fn($counts, $type) => array_merge([$type],
            array_map(fn($counter) => $counts[$counter] ?? 0, array_keys($columns))))->values()->all());

        if ($reasons = $this->stats['reasons'] ?? null) {
            arsort($reasons);
            $this->line('Rules dropped by reason: ' . collect($reasons)->map(fn($count, $reason) => "{$reason} {$count}")->implode(', '));
        }

        $max = max(0, (int) $this->option('details'));

        foreach ($this->details as $section => $lines) {
            $this->newLine();
            $this->line($section . ':');
            collect($lines)->take($max)->each(fn($line) => $this->line('  ' . $line));

            if (count($lines) > $max) {
                $this->line('  … ' . (count($lines) - $max) . ' more (--details=)');
            }
        }
    }

    protected function reportSoftDeleted(array $types): void
    {
        $count = DB::table($this->stateTable())->whereIn('type', $types)->whereNotNull('deleted_at')
            ->when($this->ids(), fn($query, $ids) => $query->whereIn('id', $ids))->count();

        if ($count) {
            $this->line("Soft-deleted rows skipped: {$count}");
        }
    }
}
