<?php

declare(strict_types=1);

namespace B13\AiLabel\Form\FormDataProvider;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Model\AiMetadata;
use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

// Decodes ai_created / ai_modified / reviewed from the ai_metadata JSON column into
// the checkbox fields rendered in the form. ai_metadata is a real TCA type=json
// column (added by AddAiMetaFieldsToTca) with 'nullable'/'default' => null set, so by
// the time this runs, databaseRow['ai_metadata'] is always either an already-decoded
// array or null - decoded by DatabaseEditRow (edit) or TcaJson (new), see the
// 'depends' on both in ext_localconf.php; null is preserved rather than forced to ''
// by DatabaseRowDefaultValues thanks to the TCA nullable/default config.
final class EnrichAiMetaData implements FormDataProviderInterface
{
    public function addData(array $result): array
    {
        if (!isset($result['processedTca']['columns']['ai_created'])) {
            return $result;
        }

        $metadata = AiMetadata::fromArray($result['databaseRow']['ai_metadata'] ?? null);

        $result['databaseRow']['ai_created'] = (int)$metadata->isAiCreated();
        $result['databaseRow']['ai_modified'] = (int)$metadata->isAiModified();
        $result['databaseRow']['reviewed'] = (int)$metadata->isReviewed();

        return $result;
    }
}
