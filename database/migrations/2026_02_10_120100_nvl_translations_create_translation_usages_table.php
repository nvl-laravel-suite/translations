<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Config\PackageStorage;
use Nvl\Translations\Definitions\Tables\TranslationsTables;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('translations');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection(PackageStorage::connection('translations'))->create(TranslationsTables::get(TranslationsTables::Usages), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('identity_hash', 64)->unique();
            $table->string('scope_type', 32)->nullable();
            $table->string('scope_name', 120)->nullable();
            $table->string('format', 16);
            $table->uuid('scan_id')->nullable();
            $table->text('full_key');
            $table->text('file_path');
            $table->unsignedInteger('line');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();

            $table->index(['last_seen_at'], 'translation_usages_last_seen_index');
            $table->index(['scope_type', 'scope_name'], 'translation_usages_scope_index');
            $table->index('scan_id', 'translation_usages_scan_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(PackageStorage::connection('translations'))->dropIfExists(TranslationsTables::get(TranslationsTables::Usages));
    }
};
