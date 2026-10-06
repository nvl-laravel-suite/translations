<?php

declare(strict_types=1);

namespace Nvl\Translations\Enums;

use Nvl\Support\Contracts\ResponseCode;

/**
 * @api
 * Stable success and failure discriminators owned by the translation workspace.
 */
enum TranslationsResponseCode: string implements ResponseCode
{
    case OperationFailed = 'operation_failed';
    case InvalidTranslationInput = 'invalid_translation_input';
    case StaleTranslationWorkspace = 'stale_translation_workspace';
    case TranslationSyncConflict = 'translation_sync_conflict';
    case TranslationWorkspaceLocked = 'translation_workspace_locked';

    case Updated = 'updated';
    case Imported = 'imported';
    case Exported = 'exported';
    case Scanned = 'scanned';
}
