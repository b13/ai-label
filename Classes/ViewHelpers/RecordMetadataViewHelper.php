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

use B13\AiLabel\Domain\Model\AiMetadata;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Resolves the AiMetadata object for a content record (or any other applicable
 * table row) - no markup, no decision-making, just the same domain object used
 * everywhere else in this extension, handed through to the frontend template.
 *
 * Returns the object itself, so it can be used inline...
 *
 * ```
 *    <f:variable name="aiMetadata" value="{ailabel:recordMetadata(record: data)}" />
 * ```
 *
 * ...or assign it directly via the optional "as" argument:
 *
 * ```
 *    <ailabel:recordMetadata record="{data}" as="aiMetadata" />
 * ```
 */
final class RecordMetadataViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('record', 'array', 'Content record (or any other applicable table row) to check', true);
        $this->registerArgument('as', 'string', 'Optional: variable name to assign the AiMetadata object to');
    }

    public function render(): ?AiMetadata
    {
        $metadata = AiMetadata::fromJsonString($this->arguments['record']['tx_ailabel_metadata'] ?? null);

        if ($this->arguments['as'] !== null) {
            $this->renderingContext->getVariableProvider()->add($this->arguments['as'], $metadata);
            return null;
        }

        return $metadata;
    }
}
