<?php

declare(strict_types=1);

namespace B13\AiLabel\Domain\Model;

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
