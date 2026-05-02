<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->bigIncrements('sms_message_id');
            $table->string('reference_number', 50)->nullable()->index();
            $table->string('template_tag', 120)->nullable()->index();
            $table->string('direction', 20);
            $table->string('provider_message_id')->nullable();
            $table->string('provider_original_message_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('from_number', 30)->nullable();
            $table->string('to_number', 30)->nullable();
            $table->string('normalized_from_number', 20)->nullable()->index();
            $table->string('normalized_to_number', 20)->nullable()->index();
            $table->string('counterparty_number', 20)->nullable()->index();
            $table->text('message_body');
            $table->timestamp('provider_received_at')->nullable()->index();
            $table->json('provider_payload')->nullable();
            $table->timestamps();

            $table->unique(['direction', 'provider_message_id'], 'sms_messages_direction_provider_unique');

            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('branch_id')
                ->references('branch_id')
                ->on('branches')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
