<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kompo\Searchbar\Models\SearchStateType;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('search_states', function (Blueprint $table) {
            addMetaData($table);

            $table->string('name');
            $table->longText('raw_state');

            $table->tinyInteger('type')->default(SearchStateType::USER);
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_states');
    }
};
