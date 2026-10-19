<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests\Fixtures;

use Nvl\Translations\Models\TranslationOverride;

/** Proves actual configured subclass creation hooks remain effective. */
final class ConfiguredOverride extends TranslationOverride
{
    /** Apply a visible creation hook without changing primary ownership facts. */
    protected static function booted(): void
    {
        self::creating(static function (self $model): void {
            $model->value = strtoupper($model->value);
        });
    }
}
