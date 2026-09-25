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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            // ADR-0018: sha256 содержимого — адрес blob в kb-documents; одна запись реестра на загрузку, blob может быть общим
            $table->char('digest', 64)->index();
            $table->string('mime_type');
            $table->string('original_name');
            $table->unsignedBigInteger('size');
            $table->string('sku')->index();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('access_level');
            $table->string('owner_department');
            $table->date('document_date')->nullable();
            $table->string('status');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
