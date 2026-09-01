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

use B13\AiLabel\Domain\Model\WatermarkOverride;
use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

// Decodes tx_ailabel_watermark_position / tx_ailabel_watermark_color / tx_ailabel_watermark_width
// from the tx_ailabel_watermark JSON column into the form's select fields - same pattern as
// EnrichAiMetaData, only present on sys_file_metadata's TCA ("baked" mode only).
final class EnrichWatermarkOverride implements FormDataProviderInterface
{
    public function addData(array $result): array
    {
        if (!isset($result['processedTca']['columns']['tx_ailabel_watermark_position'])) {
            return $result;
        }

        $override = WatermarkOverride::fromArray($result['databaseRow']['tx_ailabel_watermark'] ?? null);

        $position = $override->getPosition();
        $color = $override->getColor();
        $width = $override->getWidth();
        $result['databaseRow']['tx_ailabel_watermark_position'] = $position === null ? '' : $position->value;
        $result['databaseRow']['tx_ailabel_watermark_color'] = $color === null ? '' : $color->value;
        $result['databaseRow']['tx_ailabel_watermark_width'] = $width === null ? '' : $width->value;

        return $result;
    }
}
