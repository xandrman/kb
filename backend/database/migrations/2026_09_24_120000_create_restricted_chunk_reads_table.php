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
        // ТЗ 6.4 и 9.4: аудит-лог доступа пользователей к ограниченным документам — кто получил ответ по грифованному чанку
        Schema::create('restricted_chunk_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('chunk_id')->nullable();
            // Гриф на момент чтения: у документа он может смениться позже
            $table->string('access_level');
            $table->json('page_numbers')->nullable();
            // Вопрос уже после маскирования ПДн, как в журнале guardrails
            $table->text('question');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['document_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restricted_chunk_reads');
    }
};
