<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_appeals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exam_record_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('question_id')->nullable();
            $table->string('appeal_type')->default('score');
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('original_score', 8, 2);
            $table->decimal('final_score', 8, 2)->nullable();
            $table->text('teacher_opinion')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->foreign('exam_record_id')->references('id')->on('exam_records')->onDelete('cascade');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('question_id')->references('id')->on('questions')->onDelete('set null');
            $table->foreign('handled_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['exam_record_id']);
            $table->index(['student_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_appeals');
    }
};
