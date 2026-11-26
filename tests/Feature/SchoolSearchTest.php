<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\School;
use App\Support\SchoolSearch;
use Tests\TestCase;

/** 26-11-2026 performance, module D: App\Support\SchoolSearch (escaped LIKE, FULLTEXT query for MySQL). */
class SchoolSearchTest extends TestCase
{
    public function test_percent_and_underscore_are_found_as_typed(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $school = $this->schoolIn($cluster);
        $school->update(['name' => 'Model 100% School']);
        $other = School::where('cluster_id', $cluster->id)->where('id', '!=', $school->id)->firstOrFail();
        $other->update(['name' => 'Model 100 X School']);

        $names = SchoolSearch::apply(School::query(), '100%')->pluck('name')->all();
        $this->assertSame(['Model 100% School'], $names); // "%" used to match anything
        $this->assertSame([], SchoolSearch::apply(School::query(), 'Model_100')->pluck('name')->all()); // "_" is not a wildcard

        // the suggestions box and Find School use it
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $items = $this->actingAs($officer)->getJson(route('schools.suggest', ['q' => '100%']))->assertOk()->json('items');
        $this->assertSame(['Model 100% School'], array_column($items, 'name'));
    }

    public function test_digits_search_the_udise_prefix_and_modes_differ(): void
    {
        $school = $this->schoolIn(Cluster::orderBy('id')->first());
        $prefix = substr($school->udise_code, 0, 7);
        $this->assertContains($school->id, SchoolSearch::apply(School::query(), $prefix, 'split')->pluck('id')->all());
        $this->assertContains($school->id, SchoolSearch::apply(School::query(), $prefix, 'either')->pluck('id')->all());
        $this->assertNotContains($school->id, SchoolSearch::apply(School::query(), substr($school->udise_code, 3, 5), 'split')->pluck('id')->all()); // prefix only
    }

    public function test_live_search_uses_like_where_there_is_no_fulltext(): void
    {
        $school = $this->schoolIn(Cluster::orderBy('id')->first());
        $part = mb_substr($school->name, 2, 4); // a part of a word still matches (SQLite: LIKE)
        $rows = SchoolSearch::live(School::query(), $part, fn ($q) => $q->get());
        $this->assertContains($school->id, $rows->pluck('id')->all());
    }

    public function test_boolean_query_keeps_searchable_words_only(): void
    {
        $this->assertSame('+govt* +kokrajhar*', SchoolSearch::booleanQuery('Govt. LP, Kokrajhar'));
        $this->assertSame('+lps*', SchoolSearch::booleanQuery('the lps'));     // stopword and short words dropped
        $this->assertNull(SchoolSearch::booleanQuery('L.P'));                  // nothing usable: LIKE is used
        $this->assertSame('+abc*', SchoolSearch::booleanQuery('+abc*"'));       // boolean operators never reach MATCH
    }
}
