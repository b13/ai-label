<?php

declare(strict_types=1);

namespace B13\AiLabel\DataProcessing;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Model\AiMetadata;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

// Exposes the current content element's ai_metadata as an AiMetadata object,
// under a Fluid variable (default "aiMetadata"). No decision-making, no markup -
// just the same domain object AiMetaDataHandlerHook already works with, handed
// through to the frontend template. Register via TypoScript:
// dataProcessing.10 = B13\AiLabel\DataProcessing\AiLabelProcessor
// (or the short alias "ai-label", see Configuration/Services.yaml).
#[Autoconfigure(public: true)]
final class AiLabelProcessor implements DataProcessorInterface
{
    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $targetVariableName = $cObj->stdWrapValue('as', $processorConfiguration, 'aiMetadata');
        $record = $processedData['data'] ?? $cObj->data;
        $processedData[$targetVariableName] = AiMetadata::fromJsonString($record['ai_metadata'] ?? null);

        return $processedData;
    }
}
