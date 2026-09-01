<?php

declare(strict_types=1);

namespace B13\AiLabel\Event;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

// Dispatched once per processed file variant ProcessedFileInvalidator actually
// deletes (after an AI flag or watermark override change) - lets other extensions
// react to the removal, e.g. purge that same variant from a CDN.
final readonly class BeforeProcessedFileInvalidatedEvent
{
    public function __construct(
        public ?string $processedFilePublicUrl,
    ) {
    }
}
