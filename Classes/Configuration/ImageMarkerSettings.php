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
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

// Deliberately ExtensionConfiguration and not a Site Set setting: the "baked" mode
// hooks into FAL image processing (see B13\AiLabel\Imaging\AiWatermarkProcessor),
// which also runs outside any site context - backend thumbnails, CLI, the scheduler.
// A per-site setting would be unresolvable exactly there, so one global switch is the
// honest model. The "overlay" mode alone could have been a Set setting, but splitting
// one user-facing choice across two configuration mechanisms is worse than the
// slightly coarser scope.
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
}
