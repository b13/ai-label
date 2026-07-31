<?php

declare(strict_types=1);

namespace B13\AiLabel\Domain\Model;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

// Presentation-oriented value object: label/color for a given AiMetadata's
// review state, built once by AiMetadataBadgeFactory instead of every call site
// (record list dropdown, file list dropdown, layout module badge, form legend)
// separately deciding what "review required" or "reviewed" should look like.
final readonly class ReviewStatus
{
    public function __construct(
        public string $label,
        public string $badgeClass,
    ) {
    }
}
