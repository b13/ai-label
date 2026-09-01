<?php

declare(strict_types=1);

namespace B13\AiLabel\Configuration;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Enum\ImageMarkerMode;
use B13\AiLabel\Domain\Enum\WatermarkColor;
use B13\AiLabel\Domain\Enum\WatermarkPosition;
use B13\AiLabel\Domain\Enum\WatermarkWidth;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

// ExtensionConfiguration, not a Site Set: "baked" mode hooks into FAL processing,
// which also runs outside any site context (backend thumbnails, CLI, scheduler).
final class ImageMarkerSettings
{
    public function __construct(private readonly ExtensionConfiguration $extensionConfiguration)
    {
    }

    public function getMode(): ImageMarkerMode
    {
        try {
            $value = $this->extensionConfiguration->get('ai_label', 'imageMarker');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            // Extension installed but never configured - the pre-existing behaviour.
            return ImageMarkerMode::Off;
        }

        return ImageMarkerMode::tryFrom(is_string($value) ? $value : '') ?? ImageMarkerMode::Off;
    }

    // Global default for "baked" mode. Bottom right matches the old, hardcoded position.
    public function getWatermarkPosition(): WatermarkPosition
    {
        try {
            $value = $this->extensionConfiguration->get('ai_label', 'watermarkPosition');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return WatermarkPosition::BottomRight;
        }

        return WatermarkPosition::tryFrom(is_string($value) ? $value : '') ?? WatermarkPosition::BottomRight;
    }

    // Global default for "baked" mode. Black matches the old, hardcoded color.
    public function getWatermarkColor(): WatermarkColor
    {
        try {
            $value = $this->extensionConfiguration->get('ai_label', 'watermarkColor');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return WatermarkColor::Black;
        }

        return WatermarkColor::tryFrom(is_string($value) ? $value : '') ?? WatermarkColor::Black;
    }

    // Global default for "baked" mode. Regular (160px) matches the old, hardcoded width.
    public function getWatermarkWidth(): WatermarkWidth
    {
        try {
            $value = $this->extensionConfiguration->get('ai_label', 'watermarkWidth');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return WatermarkWidth::Regular;
        }

        return (is_numeric($value) ? WatermarkWidth::tryFrom((int)$value) : null) ?? WatermarkWidth::Regular;
    }
}
