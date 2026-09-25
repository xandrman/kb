<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Длительность остальных этапов, секунды: вместе с извлечением — бюджет ТЗ 6.2 на документ
            $table->float('indexing_time')->nullable();
            $table->float('graph_time')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['indexing_time', 'graph_time']);
        });
    }
};
