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

// Which pre-rasterised badge variant the "baked" watermark composites into the
// image - see the ai_generated_{color}.png/ai_modified_{color}.png files under
// Resources/Public/Icons/.
enum WatermarkColor: string
{
    case Black = 'black';
    case White = 'white';
}
