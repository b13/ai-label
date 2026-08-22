<?php

declare(strict_types=1);

namespace B13\AiLabel\Hooks;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Enum\WatermarkColor;
use B13\AiLabel\Domain\Enum\WatermarkPosition;
use B13\AiLabel\Domain\Model\WatermarkOverride;
use B13\AiLabel\Imaging\ProcessedFileInvalidator;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\DataHandling\DataHandler;

// Folds tx_ailabel_watermark_position / tx_ailabel_watermark_color into the
// tx_ailabel_watermark JSON column on sys_file_metadata - same virtual-field-to-JSON
// pattern as AiMetaDataHandlerHook, but simpler: no review-workflow business rule,
// so whatever was submitted just wins, no AiLabelApi/nested-DataHandler needed.
#[Autoconfigure(public: true)]
final class AiWatermarkOverrideHandlerHook
{
    /** @var array<string, WatermarkOverride> */
    private array $pendingValues = [];

    public function __construct(private readonly ProcessedFileInvalidator $processedFileInvalidator)
    {
    }

    // Same reason as AiMetaDataHandlerHook: these virtual fields have no real
    // column, so DataHandler reading $currentRecord[$col] for them would crash.
    public function processDatamap_preProcessFieldArray(
        array &$incomingFieldArray,
        string $table,
        int|string $id,
        DataHandler $dataHandler
    ): void {
        if (
            !array_key_exists('tx_ailabel_watermark_position', $incomingFieldArray)
            && !array_key_exists('tx_ailabel_watermark_color', $incomingFieldArray)
        ) {
            return;
        }

        $position = WatermarkPosition::tryFrom((string)($incomingFieldArray['tx_ailabel_watermark_position'] ?? ''));
        $color = WatermarkColor::tryFrom((string)($incomingFieldArray['tx_ailabel_watermark_color'] ?? ''));
        $this->pendingValues[$table . ':' . $id] = (new WatermarkOverride())->withPosition($position)->withColor($color);
        unset($incomingFieldArray['tx_ailabel_watermark_position'], $incomingFieldArray['tx_ailabel_watermark_color']);
    }

    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        int|string $id,
        array &$fieldArray,
        DataHandler $dataHandler
    ): void {
        $key = $table . ':' . $id;
        if (!isset($this->pendingValues[$key])) {
            return;
        }
        $override = $this->pendingValues[$key];
        unset($this->pendingValues[$key]);

        $fieldArray['tx_ailabel_watermark'] = $override->toArray();

        // FAL caches processed files on keys that don't change when only the
        // override does - an update has to flush them, or the stale render stays.
        // New records have no processed variants yet.
        if ($status === 'update' && $table === 'sys_file_metadata') {
            $this->processedFileInvalidator->invalidateForFileMetadata((int)$id);
        }
    }
}
