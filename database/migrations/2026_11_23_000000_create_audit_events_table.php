<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        /** @var string|null $connection */
        $connection = config('audit-trail.connection');

        return $connection;
    }

    public function up(): void
    {
        Schema::create($this->tableName(), function (Blueprint $table): void {
            $table->id();
            $table->dateTime('occurred_at', 3)->index();
            $table->string('event', 40)->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_email')->nullable();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('panel')->nullable();
            $table->string('route')->nullable();
            $table->text('url')->nullable();
            $table->string('method', 10)->nullable();
            $table->string('action')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->char('session_hash', 64)->nullable();
            $table->string('source', 20)->index();
            $table->boolean('inferred')->default(false);
            $table->json('evidence')->nullable();
            $table->string('evidence_key')->nullable()->unique();
            $table->timestamp('created_at', 3)->nullable();
            $table->char('hash', 64)->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['team_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }

    private function tableName(): string
    {
        /** @var string $table */
        $table = config('audit-trail.table', 'audit_events');

        return $table;
    }
};
