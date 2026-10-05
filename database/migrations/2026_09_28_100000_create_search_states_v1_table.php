<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The v1 payloads (serialized rules) of the search_states rows searchbar:migrate-states converted to format v2,
     * with a hash of the v2 payload it wrote: --rollback puts a v1 payload back while its row still holds that v2
     * payload. A row deleted (pruned) takes its payload with it.
     */
    public function up(): void
    {
        Schema::create('search_states_v1', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_state_id')->unique()->constrained('search_states')->cascadeOnDelete();
            $table->longText('raw_state');
            $table->char('converted_hash', 64);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_states_v1');
    }
};
