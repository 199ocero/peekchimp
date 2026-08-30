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
        Schema::create('chat_conversation_contexts', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_id', 36)->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->foreign('conversation_id')
                ->references('id')
                ->on(config('ai.conversations.tables.conversations', 'agent_conversations'))
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_conversation_contexts');
    }
};
