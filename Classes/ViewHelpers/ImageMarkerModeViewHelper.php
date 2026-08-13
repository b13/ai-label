<?php

declare(strict_types=1);

namespace B13\AiLabel\ViewHelpers;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Configuration\ImageMarkerSettings;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Returns the configured image marker mode ("off", "overlay" or "baked") so a template
 * can decide whether to render its own marker over an image. The setting is extension
 * configuration, which TypoScript cannot read, hence a ViewHelper rather than a
 * constant.
 *
 * ```
 *    <f:if condition="{ailabel:imageMarkerMode()} == 'overlay'">...</f:if>
 * ```
 *
 * Unlike the two metadata ViewHelpers this has constructor dependencies, so it needs
 * #[Autoconfigure(public: true)] - Fluid's ViewHelperResolver builds ViewHelpers with
 * GeneralUtility::makeInstance(), and autowiring silently does nothing without it.
 */
#[Autoconfigure(public: true)]
final class ImageMarkerModeViewHelper extends AbstractViewHelper
{
    public function __construct(private readonly ImageMarkerSettings $settings)
    {
    }

    public function render(): string
    {
        return $this->settings->getMode()->value;
    }
}
