<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The end of format v1 (serialized rules): the stores read v2 only now. Drops the v1 payloads kept by the one-time
     * conversion (searchbar:migrate-states, removed), and the rows still in v1 (favorites, links and states it left as
     * they were): nothing reads them any more.
     */
    public function up(): void
    {
        Schema::dropIfExists('search_states_v1');

        DB::table('search_states')
            ->where(fn ($query) => $query->whereNull('raw_state')->orWhere('raw_state', 'not like', '{"v":2%'))
            ->delete();
    }

    public function down(): void
    {
        // Not restored: v1 isn't read any more.
    }
};
