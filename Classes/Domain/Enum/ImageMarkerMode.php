<?php

declare(strict_types=1);

namespace B13\AiLabel\Domain\Enum;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

// How a flagged *image* is marked in the frontend, on top of the per-content-element
// marker that always renders (Resources/Private/Partials/AiLabel.html). Off is the
// default: the extension behaves exactly as it did before this setting existed.
enum ImageMarkerMode: string
{
    case Off = 'off';
    case Overlay = 'overlay';
    case Baked = 'baked';
}
