<?php

declare(strict_types=1);

namespace B13\AiLabel\Form\FormDataProvider;

use B13\AiLabel\Domain\Model\AiMetadata;
use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

// Decodes ai_created / ai_modified / reviewed from the ai_metadata JSON column into
// the checkbox fields rendered in the form. ai_metadata is a real column (added via
// AddAiMetadataColumnToSchema), so DatabaseEditRow already loaded it as part of the
// row - no separate lookup needed.
final class EnrichAiMetaData implements FormDataProviderInterface
{
    public function addData(array $result): array
    {
        if (!isset($result['processedTca']['columns']['ai_created'])) {
            return $result;
        }

        $metadata = new AiMetadata($result['databaseRow']['ai_metadata'] ?? null);

        $result['databaseRow']['ai_created'] = (int)$metadata->isAiCreated();
        $result['databaseRow']['ai_modified'] = (int)$metadata->isAiModified();
        $result['databaseRow']['reviewed'] = (int)$metadata->isReviewed();

        return $result;
    }
}
