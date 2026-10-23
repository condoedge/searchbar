<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links of "Open in a table" pages are search states too (type LINK), found by an unguessable token: the whole
     * state no longer travels in the URL.
     */
    public function up(): void
    {
        Schema::table('search_states', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('search_states', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });
    }
};
