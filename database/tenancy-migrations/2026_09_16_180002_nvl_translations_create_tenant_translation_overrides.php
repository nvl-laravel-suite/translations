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

    public function up(): void
    {
        if (Schema::connection(PackageStorage::connection('translations'))->hasTable(TranslationsTables::get(TranslationsTables::TenantOverrides))) {
            throw new LogicException('Existing package table is not owned by this migration. Run nvl:doctor --strict and use nvl:schema:upgrade for a verified legacy installation.');
        }
        Schema::connection(PackageStorage::connection('translations'))->create(TranslationsTables::get(TranslationsTables::TenantOverrides), static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('key', 191);
            $table->string('locale', 35);
            $table->text('value');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'key', 'locale'], 'tenant_translation_override_identity_unique');
            $table->index(['tenant_id', 'updated_at'], 'tenant_translation_override_recent_idx');
        });
    }

    public function down(): void {}
};
