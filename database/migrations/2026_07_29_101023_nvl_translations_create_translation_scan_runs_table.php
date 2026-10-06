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
        Schema::connection(PackageStorage::connection('translations'))->create(TranslationsTables::get(TranslationsTables::ScanRuns), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestampTz('scanned_at', 6)->index();
            $table->unsignedInteger('files');
            $table->unsignedBigInteger('hits');
            $table->timestampsTz(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(PackageStorage::connection('translations'))->dropIfExists(TranslationsTables::get(TranslationsTables::ScanRuns));
    }
};
