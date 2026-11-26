<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * 26-11-2026 performance, module D: one place for "search schools by name or UDISE code". Use it for any new school
 * search.
 *
 * - The text is escaped, so "%" and "_" are found as typed (they used to match anything).
 * - Digits only = UDISE code prefix. Mode "split" (Find School, My Schools, suggestions): digits search the UDISE
 *   code only, anything else the name. Mode "either": UDISE prefix OR name.
 * - Names: a "contains" LIKE, which scans the table (100–200 ms on the 44,197 real schools). For the live, limited
 *   searches (suggestions, Map View search, planner) live() first uses the FULLTEXT index schools_name_ft on
 *   MySQL / MariaDB: every word of 3+ letters must start a word of the name ("kokra" finds KOKRAJHAR). When that
 *   finds nothing (or the index is missing, or the words are too short), the exact LIKE runs, so a part of a word
 *   ("okra") is still found. Full lists and filters keep the exact LIKE only, so their results never change.
 */
final class SchoolSearch
{
    /** InnoDB's default stopwords of 3+ letters: a required stopword would make MATCH find nothing. */
    private const STOPWORDS = ['about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];

    private const MIN_WORD = 3; // innodb_ft_min_token_size

    /**
     * Add the search to $query (no change when $term is empty).
     *
     * @param  'split'|'either'  $mode
     */
    public static function apply(EloquentBuilder|QueryBuilder $query, ?string $term, string $mode = 'either', string $table = 'schools', bool $fulltext = false): EloquentBuilder|QueryBuilder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $grammar = $query instanceof EloquentBuilder ? $query->getQuery()->getGrammar() : $query->getGrammar();
        $udise = $grammar->wrap("$table.udise_code");
        $name = $grammar->wrap("$table.name");
        $digits = ctype_digit($term);
        $boolean = $fulltext ? self::booleanQuery($term) : null;

        $byUdise = fn ($w) => $w->whereRaw("$udise LIKE ? ESCAPE '!'", [self::escape($term).'%']);
        $byName = $boolean !== null
            ? fn ($w) => $w->whereRaw("MATCH($name) AGAINST (? IN BOOLEAN MODE)", [$boolean])
            : fn ($w) => $w->whereRaw("$name LIKE ? ESCAPE '!'", ['%'.self::escape($term).'%']);

        if ($mode === 'split') {
            return $digits ? $byUdise($query) : $byName($query);
        }

        return $query->where(fn ($w) => $byUdise($w)->orWhere(fn ($o) => $byName($o)));
    }

    /**
     * Run a live, limited search: FULLTEXT first where it can be used, the exact LIKE when that finds nothing.
     * $run gets the searched query and returns the rows (e.g. fn ($q) => $q->limit(10)->get()).
     */
    public static function live(EloquentBuilder|QueryBuilder $query, string $term, Closure $run, string $mode = 'either', string $table = 'schools'): mixed
    {
        if (self::fulltextUsable($term)) {
            try {
                $rows = $run(self::apply(clone $query, $term, $mode, $table, true));
                if (count($rows) > 0) {
                    return $rows;
                }
            } catch (QueryException $e) {
                report($e); // e.g. the FULLTEXT index is missing on this server: the LIKE search still answers
            }
        }

        return $run(self::apply($query, $term, $mode, $table));
    }

    /** "%", "_" and the escape character "!" are matched as typed. */
    public static function escape(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }

    /** "+word* +word*" for MATCH … IN BOOLEAN MODE, or null when no word can be searched that way. */
    public static function booleanQuery(string $term): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn ($w) => mb_strlen($w) >= self::MIN_WORD && ! in_array($w, self::STOPWORDS, true)));

        return $words ? implode(' ', array_map(fn ($w) => '+'.$w.'*', array_slice($words, 0, 8))) : null;
    }

    private static function fulltextUsable(string $term): bool
    {
        return config('inspection.school_search_fulltext', true)
            && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            && ! ctype_digit(trim($term))
            && self::booleanQuery($term) !== null;
    }
}
