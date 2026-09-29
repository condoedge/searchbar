<?php

namespace Kompo\Searchbar\SearchItems\Filterables\FilterableColumn;

use Kompo\Searchbar\Elements\ServerSearchMultiSelect;
use Kompo\Searchbar\Elements\ServerSearchSelect;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\ColumnRule;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\DateRule;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\EnumRule;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\InputRelationRule;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\SelectRelationRule;
use Kompo\Searchbar\SearchItems\Rules\ColumnRule\SelectRule;
use Kompo\Searchbar\SearchItems\Rules\FilterableRule;

enum FilterableColumnTypeEnum: int
{
    case TEXT = 1;
    case ENUM = 2;
    case DATE = 3;
    case NUMBER = 4;
    case NUMBER_CURRENCY = 5;
    case SELECT = 6;
    case RELATION_SELECT = 7;
    case RELATION_INPUT = 8;

    public function operatorOptions()
    {
        return match ($this) {
            self::TEXT => [
                OperatorEnum::EQUALS_TO,
                OperatorEnum::DIFFERENT,

                OperatorEnum::CONTAINS,
                OperatorEnum::DOES_NOT_CONTAIN,
            ],
            self::ENUM => [
                OperatorEnum::IN,
                OperatorEnum::NOT_IN,
            ],
            self::RELATION_SELECT => [
                OperatorEnum::IN,
                OperatorEnum::NOT_IN,
            ],
            self::RELATION_INPUT => [
                OperatorEnum::EQUALS_TO,
                OperatorEnum::DIFFERENT,

                OperatorEnum::CONTAINS,
                OperatorEnum::DOES_NOT_CONTAIN,
            ],
            self::DATE => [
                OperatorEnum::EQUALS_TO,
                OperatorEnum::DIFFERENT,
                OperatorEnum::MORE_THAN,
                OperatorEnum::MORE_THAN_OR_EQUAL,
                OperatorEnum::LESS_THAN,
                OperatorEnum::LESS_THAN_OR_EQUAL,
                OperatorEnum::BETWEEN,
            ],
            self::NUMBER, self::NUMBER_CURRENCY => [
                OperatorEnum::EQUALS_TO,
                OperatorEnum::DIFFERENT,
                OperatorEnum::MORE_THAN,
                OperatorEnum::MORE_THAN_OR_EQUAL,
                OperatorEnum::LESS_THAN,
                OperatorEnum::LESS_THAN_OR_EQUAL,
                OperatorEnum::BETWEEN,
            ],
            self::SELECT => [
                OperatorEnum::IN,
                OperatorEnum::NOT_IN,
            ],
        };
    }

    public function getOperatorOptionsParsed()
    {
        return collect($this->operatorOptions())->mapWithKeys(
            fn($option) => [$option->value => __($option->label())]
        )->toArray();
    }

    public function defaultOperator()
    {
        return match ($this) {
            self::TEXT => OperatorEnum::CONTAINS,
            self::ENUM => OperatorEnum::IN,
            self::DATE => OperatorEnum::EQUALS_TO,
            self::NUMBER, self::NUMBER_CURRENCY => OperatorEnum::BETWEEN,
            self::RELATION_SELECT => OperatorEnum::IN,
            self::RELATION_INPUT => OperatorEnum::CONTAINS,
            self::SELECT => OperatorEnum::IN,
        };
    }

    public function rule(): string
    {
        return match ($this) {
            self::ENUM => EnumRule::class,
            self::RELATION_SELECT => SelectRelationRule::class,
            self::RELATION_INPUT => InputRelationRule::class,

            self::DATE => DateRule::class,
            self::SELECT => SelectRule::class,
            
            default => ColumnRule::class,
        };
    }

    public function getRuleInstance($params): FilterableRule
    {
        $rule = $this->rule();
        $value = $params['value'] ?? null;

        return match($this) {
            self::SELECT, self::RELATION_SELECT, self::ENUM => new $rule($params['column'], $params['operator'], is_array($value) ? $value : [$value]),

            default => new $rule($params['column'], $params['operator'], $value),
        };
    }

    /** Whether its value is picked among options (a select): only these can search their options on the server. */
    public function offersOptions(): bool
    {
        return in_array($this, [self::ENUM, self::SELECT, self::RELATION_SELECT], true);
    }

    /**
     * A select; with $serverSearch (the ajaxPayload naming the filter: ['searchbar_filter' => key]), one that loads
     * its options as the user types: a Kompo search-options request to the komponent rendering it, answered by
     * SearchKomponentUtils::searchbarRelationOptions(). Its own options are then only the selected values.
     */
    public static function selectElement(bool $multiple, ?array $serverSearch = null)
    {
        if (!$serverSearch) {
            return $multiple ? _MultiSelect() : _Select();
        }

        $minChars = self::serverSearchMinChars();

        // No retrieve method: the selected values come labelled (KeepsBuiltOptions). Named, else Kompo infers one from
        // the field name, not set yet.
        return ($multiple ? ServerSearchMultiSelect::form() : ServerSearchSelect::form())
            ->searchOptions($minChars, 'searchbarRelationOptions', 'searchbarKeepsBuiltOptions')
            ->config([
                'ajaxPayload' => $serverSearch,
                'enterMoreCharacters' => __('filter.relation-search-min-chars', ['min' => $minChars]),
            ]);
    }

    /** Characters typed before a server-search select searches (full-text ignores shorter words). */
    public static function serverSearchMinChars(): int
    {
        return max(0, (int) config('searchbar.relation-search-min-chars', 3));
    }

    /**
     * The inline editor of a pill, prefilled with $value and shaped by the rule's operator (a range for BETWEEN,
     * a multi-select for IN / NOT IN). $serverSearch: see selectElement().
     *
     * Applying and cancelling go through the pill's ✓ / ✕ links, which the searchbar JS also presses on Enter,
     * Tab / click away and Esc (see searchbarClientJs()). Per-input blur saves broke editors: moving from min to
     * max saved half a range, and a date picker blurs before its value is set. Dates therefore apply on change
     * ($onApply), when a date (or both ends of a range) is picked.
     */
    public function inlineInput($name, $onApply = null, $value = null, $options = [], ?OperatorEnum $operator = null, ?array $serverSearch = null)
    {
        $operator = $operator ?? $this->defaultOperator();
        $compact = 'mb-0 text-xs [&>div]:flex [&>div]:h-5 [&>div]:items-center [&>div>input]:!px-1';

        $value = $this->normalizeValue($value, $operator);
        [$from, $to] = is_array($value) ? array_values($value) + [null, null] : [null, null];
        $scalar = is_array($value) ? null : $value;

        return match ($this) {
            // These store ids/enum values: typed text was saved as the value and its label lookup failed.
            self::ENUM, self::RELATION_SELECT, self::SELECT => $this->inlineSelect(
                in_array($operator, [OperatorEnum::IN, OperatorEnum::NOT_IN], true)
                    ? self::selectElement(true, $serverSearch)->default(is_array($value) ? $value : null)
                    : self::selectElement(false, $serverSearch)->default($scalar),
                $name, $options, $compact,
            ),

            self::NUMBER, self::NUMBER_CURRENCY => $operator === OperatorEnum::BETWEEN
                ? _Flex(
                    // Only the min takes the focus: with both, the last mounted (the max) won.
                    _InputNumber()->noInputWrapper()->name($name . '[0]', false)->default($from)->placeholder('filter.min')
                        ->dontSubmitOnEnter()->focusOnLoad()->class($compact . ' w-16'),
                    _Html('—')->class('text-xs px-1 text-gray-400'),
                    _InputNumber()->noInputWrapper()->name($name . '[1]', false)->default($to)->placeholder('filter.max')
                        ->dontSubmitOnEnter()->class($compact . ' w-16'),
                )->class('items-center gap-0.5')
                : _InputNumber()->noInputWrapper()->name($name, false)->default($scalar)->dontSubmitOnEnter()->focusOnLoad()->class($compact . ' w-20'),

            self::DATE => ($operator === OperatorEnum::BETWEEN ? _DateRange()->default([$from, $to]) : _Date()->default($scalar))
                ->noInputWrapper()->name($name, false)->class($compact . ($operator === OperatorEnum::BETWEEN ? ' w-48' : ' w-28'))
                ->when($onApply, fn($el) => $el->onChange($onApply)),

            default => _Input()->default($scalar)->noInputWrapper()->name($name, false)->placeholder('...')
                ->dontSubmitOnEnter()->noAutocomplete()->focusOnLoad()->class($compact . ' w-32'),
        };
    }

    /**
     * The pill rows scroll horizontally (overflow hidden on y), which clipped the dropdown: it is fixed-positioned
     * under the input instead (condoedge utils .select-over-modal, positioned from the viewport).
     */
    public function inlineSelect($select, $name, $options, $compact = 'mb-0 text-xs')
    {
        $id = 'inline-select-' . \Str::slug($name) . '-' . \Str::random(4);

        // Its own stacking context above the open search panel (z 110): the list opens right where the panel starts.
        return $select->options($options)->noInputWrapper()->name($name, false)->id($id)
            ->class($compact . ' w-40 select-over-modal')
            ->focusOnLoad()
            ->onFocus(fn($e) => $e->run('() => {
                const el = document.getElementById("' . $id . '");
                const box = el && (el.closest(".vlTaggableInput") || el).getBoundingClientRect();
                const container = el && el.closest(".select-over-modal");
                if (!box || !container) return;
                container.style.setProperty("--select-translate", box.bottom + "px", "important");
                container.style.setProperty("--select-width", Math.max(box.width, 220) + "px", "important");
                container.style.setProperty("position", "relative");
                container.style.setProperty("z-index", "120");
            }'));
    }

    /**
     * A value in the shape its operator queries with: [from, to] for BETWEEN (a missing bound is open), a list
     * without blanks for IN / NOT IN, a trimmed scalar otherwise. Null when nothing is left (no filter).
     */
    public function normalizeValue($value, ?OperatorEnum $operator = null)
    {
        $operator = $operator ?? $this->defaultOperator();
        $isBlank = fn($v) => $v === null || (is_string($v) && trim($v) === '') || (is_array($v) && !$v);
        $clean = fn($v) => is_string($v) ? trim($v) : $v;

        if ($operator === OperatorEnum::BETWEEN) {
            // A range picker posts a missing bound as the string "null" (FormData of a null).
            $isBlank = fn($v) => $v === null || (is_string($v) && in_array(trim($v), ['', 'null', 'undefined'], true)) || (is_array($v) && !$v);
            [$from, $to] = array_values(is_array($value) ? $value : [$value]) + [null, null];
            $range = [$isBlank($from) || is_array($from) ? null : $clean($from), $isBlank($to) || is_array($to) ? null : $clean($to)];

            return $range === [null, null] ? null : $range;
        }

        if (in_array($operator, [OperatorEnum::IN, OperatorEnum::NOT_IN], true)) {
            $values = collect(is_array($value) ? $value : [$value])->flatten()
                ->reject($isBlank)->map($clean)->values()->all();

            return $values ?: null;
        }

        if (is_array($value)) {
            $value = collect($value)->flatten()->first(fn($v) => !$isBlank($v));
        }

        return $isBlank($value) ? null : $clean($value);
    }

    /**
     * The text typed in the searchbar as this column's rule params (['value' => .., 'operator' => ..]), or null
     * when it can't be a value of this column: the "Search by" chip then opens the pill editor instead.
     */
    public function parseSearchText(string $text, $options = null): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return match ($this) {
            self::TEXT, self::RELATION_INPUT => ['value' => $text],
            self::NUMBER, self::NUMBER_CURRENCY => self::parseNumberText($text),
            self::DATE => self::parseDateText($text),
            // Relation options are whole tables: not matched from the text.
            self::ENUM, self::SELECT => self::matchOptionLabels($text, collect(is_callable($options) ? $options() : $options)),
            default => null,
        };
    }

    /** "5" is "= 5", "5-10" / "5 à 10" a range, ">= 5" / "< 10" that comparison. */
    protected static function parseNumberText(string $text): ?array
    {
        $number = '-?\d+(?:[.,]\d+)?';
        $compact = preg_replace('/[\s\x{00A0}$]/u', '', $text);
        $toNumber = fn(string $n) => 0 + str_replace(',', '.', $n);

        if (preg_match("/^($number)$/", $compact, $m)) {
            return ['operator' => OperatorEnum::EQUALS_TO, 'value' => $toNumber($m[1])];
        }

        if (preg_match("/^($number)(?:-|–|—|\.\.|to|à|a)($number)$/iu", $compact, $m)) {
            return ['operator' => OperatorEnum::BETWEEN, 'value' => [$toNumber($m[1]), $toNumber($m[2])]];
        }

        if (preg_match("/^(>=|<=|≥|≤|>|<)($number)$/u", $compact, $m)) {
            $operator = match ($m[1]) {
                '>' => OperatorEnum::MORE_THAN,
                '<' => OperatorEnum::LESS_THAN,
                '>=', '≥' => OperatorEnum::MORE_THAN_OR_EQUAL,
                default => OperatorEnum::LESS_THAN_OR_EQUAL,
            };

            return ['operator' => $operator, 'value' => $toNumber($m[2])];
        }

        return null;
    }

    /** A year is that whole year; a full date (Y-m-d, d/m/Y, d-m-Y, d.m.Y) is that day. */
    protected static function parseDateText(string $text): ?array
    {
        if (preg_match('/^(19|20)\d{2}$/', $text)) {
            return ['operator' => OperatorEnum::BETWEEN, 'value' => ["$text-01-01", "$text-12-31"]];
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            $date = \DateTime::createFromFormat('!' . $format, $text);

            // format() round-trip: rejects overflowing dates (31/02 would become 03/03).
            if ($date && $date->format($format) === $text) {
                return ['operator' => OperatorEnum::EQUALS_TO, 'value' => $date->format('Y-m-d')];
            }
        }

        return null;
    }

    /** Options whose label is the text (else contains it), case and accent insensitive; at most 10. */
    protected static function matchOptionLabels(string $text, $options): ?array
    {
        $normalize = fn($label) => \Str::lower(\Str::ascii(trim(strip_tags(__((string) $label)))));
        $needle = $normalize($text);

        $labels = collect($options)->except('all')
            ->filter(fn($label) => is_scalar($label) || $label instanceof \Stringable)
            ->map($normalize);

        $matches = $labels->filter(fn($label) => $label === $needle)->keys();

        if ($matches->isEmpty() && mb_strlen($needle) >= 2) {
            $matches = $labels->filter(fn($label) => str_contains($label, $needle))->keys();
        }

        return $matches->isEmpty() || $matches->count() > 10 ? null : ['value' => $matches->values()->all()];
    }

    /** The value input of the custom filters modal and the rule form. $serverSearch: see selectElement(). */
    public function input($params = [], ?OperatorEnum $operator = null, ?array $serverSearch = null)
    {
        return match ($this) {
            self::TEXT => _Input(),
            self::ENUM, self::RELATION_SELECT, self::SELECT => self::selectElement(in_array($operator, [OperatorEnum::IN, OperatorEnum::NOT_IN], true), $serverSearch)
                ->options($params)->overModal('select' . \Str::random(5) . time()),
            self::DATE => match ($operator) {
                OperatorEnum::BETWEEN => _DateRange(),
                default => _Date(),
            },
            self::NUMBER => match ($operator) {
                OperatorEnum::BETWEEN => _NumberRange(),
                default => _InputNumber(),
            },
            self::NUMBER_CURRENCY => match ($operator) {
                OperatorEnum::BETWEEN => _NumberRange()->rIcon('<span>$</span>')->inputClass('input-number-no-arrows text-right'),
                default => _InputDollar(),
            },
            self::RELATION_INPUT => _Input(),
        };
    }
}