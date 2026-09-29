<?php

namespace Kompo\Searchbar\SearchItems\Rules;

use Kompo\Searchbar\SearchItems\SearchItem;

abstract class Rule extends SearchItem
{
    const SEPARATOR = ',';

    /**
     * Stable id of this rule in its state (SearchState gives it): pills, pill editors, custom filters modal rows and
     * searchstate/* requests address the rule by it. Positional indexes shifted when rules changed in another tab or
     * request, and the wrong rule was edited or removed. Serialized with the rule: kept by every store.
     */
    protected ?string $id = null;

    /** Rules unserialized without an id (stored before ids): their state gives them positional ids, see SearchState. */
    private static ?\WeakMap $unserializedWithoutId = null;

    public function query($query)
    {
        return $this->decorateQuery($query);
    }

    abstract public function decorateQuery($query);

    abstract public function toArray();
    abstract public function renderContent();

    /**
     * The rule as one line of plain text ("Email: contains gmail"), for the filters described above a table's
     * export (SearchbarTableExport), or null when it doesn't filter or can't say. Host rules may override it.
     */
    public function describe(): ?string
    {
        return null;
    }

    public function getState()
    {
        return $this->searchContextService->getStore()->getState();
    }

    /** $index: unused, a rule is addressed by its id (kept for callers passing it). */
    public function render($index = null, $withDeleteButton = true)
    {
        return _Rows(
            _Flex(
                $this->renderContent(),
            )->class('gap-4'),
        );
    }

    // IDS

    /** Never numeric ('r' + 8 hex): an id can't be mistaken for an index of the requests rendered before ids. */
    public static function newId(): string
    {
        return 'r' . bin2hex(random_bytes(4));
    }

    /** What a request may name as a rule id (it is also written into field names: inline_{id}, value_{id}). */
    public static function isValidId($id): bool
    {
        return is_string($id) && preg_match('/^[a-z][a-z0-9]{1,15}$/', $id) === 1;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Stored before ids (session objects, v1 links and favorites): such a state is read again on each request until
     * it is stored, so its rules need ids that are the same on every read. Remembered outside the object, so the
     * mark is never stored.
     */
    public function __wakeup()
    {
        if ($this->id === null) {
            self::$unserializedWithoutId ??= new \WeakMap();
            self::$unserializedWithoutId[$this] = true;
        }
    }

    public function wasUnserializedWithoutId(): bool
    {
        return $this->id === null && isset(self::$unserializedWithoutId[$this]);
    }
}
