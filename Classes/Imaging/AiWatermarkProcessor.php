<?php

declare(strict_types=1);

namespace B13\AiLabel\Imaging;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Configuration\ImageMarkerSettings;
use B13\AiLabel\Domain\Enum\ImageMarkerMode;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Resource\Processing\LocalImageProcessor;
use TYPO3\CMS\Core\Resource\Processing\TaskInterface;

// Registered in ext_localconf.php ahead of core's LocalImageProcessor, which this
// extends: canProcessTask() claims a task only when the file actually needs a baked
// watermark, so in every other case the registry falls straight through to core and
// this class has no footprint at all.
//
// A FAL processor - rather than the more obvious AfterFileProcessingEvent - is the
// only correct hook for this. FileProcessingService::processFile() dispatches that
// event unconditionally, *outside* its `if ($task->fileNeedsProcessing())` branch, so
// it also fires on cache hits; a listener compositing there would stamp the badge on
// again on every single request until the image is nothing but badges. A processor
// runs inside that branch, i.e. exactly once per processed variant, which also means
// the result is cached like any other processed file - no extra invalidation needed
// beyond flushing when the AI flag itself changes (see AiMetaDataHandlerHook).
//
// #[Autoconfigure(public: true)]: ProcessorRegistry instantiates this through
// GeneralUtility::makeInstance(), outside the normal object graph, and this class does
// have constructor dependencies - without the attribute they are silently not injected.
#[Autoconfigure(public: true)]
final class AiWatermarkProcessor extends LocalImageProcessor
{
    public function __construct(
        private readonly ImageMarkerSettings $settings,
        private readonly AiWatermark $watermark,
    ) {
    }

    public function canProcessTask(TaskInterface $task): bool
    {
        return $this->settings->getMode() === ImageMarkerMode::Baked
            && parent::canProcessTask($task)
            && $this->watermark->appliesTo($task->getSourceFile());
    }

    public function processTask(TaskInterface $task): void
    {
        parent::processTask($task);

        if (!$task->isExecuted() || !$task->isSuccessful()) {
            return;
        }

        // getTargetFileName() is the name core itself would have given the variant. It
        // only matters for images that needed no scaling, see AiWatermark::applyTo().
        $this->watermark->applyTo($task->getTargetFile(), $task->getSourceFile(), $task->getTargetFileName());
    }
}
