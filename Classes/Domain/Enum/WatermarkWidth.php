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

// Deliberately a closed set of two fixed pixel widths, not a free-form number - an
// editor picking an arbitrary value could shrink the badge into illegibility or blow
// it up past its own artwork's resolution. Regular matches the previous, fixed width.
enum WatermarkWidth: int
{
    case Regular = 160;
    case Small = 80;
}
