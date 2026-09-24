<?php

declare(strict_types=1);

namespace B13\AiLabel\Legacy\EventListener;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Frontend\WatermarkAltTextAppender;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

#[AsEventListener(identifier: 'ai-label/legacy-append-watermark-label-to-alt-text')]
final class AppendWatermarkLabelToAltText
{
    public function __construct(
        private readonly WatermarkAltTextAppender $appender,
        private readonly Typo3Version $typo3Version,
    ) {
    }

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        if ($this->typo3Version->getMajorVersion() >= 14) {
            return;
        }

        $controller = $event->getController();
        $controller->content = $this->appender->appendTo((string)$controller->content, $event->getRequest());
    }
}
