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
        // FR-8, ТЗ 6.4 и 9.4: журнал срабатываний guardrails — основание для отчёта об отсутствии утечек ПДн
        Schema::create('guardrail_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('checkpoint');
            $table->string('action');
            // Причину собирает код из структурированных данных; значений ПДн в ней нет
            $table->string('reason');
            // Вопрос уже после маскирования ПДн; текст ответа не хранится — при утечке контекста в нём то, что утекло
            $table->text('question');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['checkpoint', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guardrail_events');
    }
};
