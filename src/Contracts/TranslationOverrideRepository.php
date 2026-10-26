<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Enums\SystemActor;

/**
 * Manages literal copy overrides within the currently admitted owner.
 *
 * @api
 */
interface TranslationOverrideRepository
{
    /** Read one allowed override from the current owner. */
    public function get(Authenticatable|SystemActor $actor, string $key, string $locale): ?string;

    /** Write one expected revision without changing source files or another owner's copy. */
    public function set(Authenticatable|SystemActor $actor, string $key, string $locale, string $value, int $expectedRevision): void;

    /** Delete only the current owner's expected revision. */
    public function forget(Authenticatable|SystemActor $actor, string $key, string $locale, int $expectedRevision): void;

    /** @return array{owner:array<string,mixed>,disk:string,path:string,sha256:string,size:int} */
    public function export(Authenticatable|SystemActor $actor, string $disk): array;
}
