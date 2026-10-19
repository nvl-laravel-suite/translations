<?php

declare(strict_types=1);

namespace Nvl\Translations\Data;

use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Immutable identity for one private native-owner copy artifact; it grants no read authority. @api */
final readonly class TranslationOverrideExportData
{
    /** Record the original owner, private object and content digest. */
    public function __construct(public OwnerReference $owner, public string $disk, public string $path, public string $sha256, public int $bytes) {}
}
