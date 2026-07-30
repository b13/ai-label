<?php

declare(strict_types=1);

namespace B13\AiLabel\Form\FormDataProvider;

use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

// Decodes ai_created / ai_modified / reviewed from the ai_metadata JSON column into
// the checkbox fields rendered in the form. ai_metadata is a real column (added via
// AddAiMetadataColumnToSchema), so DatabaseEditRow already loaded it as part of the
// row - no separate lookup needed. reviewed_by stores the be_users uid that reviewed
// the record (0 = review required); "reviewed" is just the boolean reviewed_by > 0.
final class EnrichAiMetaData implements FormDataProviderInterface
{
    public function addData(array $result): array
    {
        if (!isset($result['processedTca']['columns']['ai_created'])) {
            return $result;
        }

        $metadata = [];
        $raw = $result['databaseRow']['ai_metadata'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $result['databaseRow']['ai_created'] = (int)($metadata['ai_created'] ?? 0);
        $result['databaseRow']['ai_modified'] = (int)($metadata['ai_modified'] ?? 0);
        $result['databaseRow']['reviewed'] = (int)($metadata['reviewed_by'] ?? 0) > 0 ? 1 : 0;

        return $result;
    }
}
