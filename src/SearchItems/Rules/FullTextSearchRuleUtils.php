<?php

namespace Kompo\Searchbar\SearchItems\Rules;

use  Illuminate\Database\Query\Expression;

trait FullTextSearchRuleUtils
{
    protected function fullSearchQuery($query)
    {
        $value = $this->constructValueForFullTextSearch($this->value);

        if (!$value) {
            return $query;
        }

        $naturalLanguageValue = trim(preg_replace('/[+\-*~<>()"@]+/', ' ', $value));
        $column = $this->getColumnForFullTextSearch();

        return $query->whereRaw("MATCH(" . $column . ") AGAINST(? IN BOOLEAN MODE)", [$value])
            ->selectRaw("*, MATCH({$column}) AGAINST('{$naturalLanguageValue}' IN NATURAL LANGUAGE MODE) AS relevance")
            ->orderByRaw('relevance DESC')
            ->orderByRaw("LENGTH(" . $column . ") ASC");
    }

    protected function getColumnForFullTextSearch()
    {
        return $this->column instanceof Expression ? $this->column->getValue(\DB::getQueryGrammar()) : $this->column;
    }

    protected function constructValueForFullTextSearch($value)
    {
        if (is_null($value) || $value === '') {
            return '';
        }

        return $this->constructValueForFullTextSearchFlexible($value);
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
        if ($value === null) return '';

        // Normalize spaces
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($value === '') return '';

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
        if (count($out) > 20) {
            $out = array_slice($out, 0, 20);
        }

        return implode(' ', $out);
    }

    protected function buildPrefixes($word)
    {
        if ($this->usesNgramSearch()) {
            return '\"'. $word . '\"';
        } else {
            return $word . '*';
        }
    }
}
