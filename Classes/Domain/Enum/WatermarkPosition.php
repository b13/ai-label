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

// Which corner of the image the "baked" watermark is composited into. Values map
// 1:1 to ImageMagick -gravity keywords in AiWatermark::composite().
enum WatermarkPosition: string
{
    case TopLeft = 'top-left';
    case TopRight = 'top-right';
    case BottomLeft = 'bottom-left';
    case BottomRight = 'bottom-right';
}
