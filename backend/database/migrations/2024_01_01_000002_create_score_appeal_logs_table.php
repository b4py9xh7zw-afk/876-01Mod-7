<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_appeal_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appeal_id');
            $table->unsignedBigInteger('handler_id');
            $table->string('action');
            $table->decimal('score_adjustment', 8, 2)->default(0);
            $table->text('opinion');
            $table->string('from_status');
            $table->string('to_status');
            $table->timestamps();

            $table->foreign('appeal_id')->references('id')->on('score_appeals')->onDelete('cascade');
            $table->foreign('handler_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['appeal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_appeal_logs');
    }
};
