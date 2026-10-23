<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * searchbar:prune-links deletes each type of search state by its age (links, the navbar's working states, remembered
     * tables): with the navbar's states in rows (one per page display that changed its search), the table grows past
     * what a scan per prune should read.
     */
    public function up(): void
    {
        Schema::table('search_states', function (Blueprint $table) {
            $table->index(['type', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('search_states', function (Blueprint $table) {
            $table->dropIndex(['type', 'updated_at']);
        });
    }
};
