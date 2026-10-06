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

#[Autoconfigure(public: true)]
final class IconPathViewHelper extends AbstractViewHelper
{
    public function __construct(private readonly ImageMarkerSettings $settings)
    {
    }

    public function render(): string
    {
        return $this->settings->getIconPath();
    }
}
