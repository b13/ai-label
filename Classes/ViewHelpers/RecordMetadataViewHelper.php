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
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
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
#[Autoconfigure(public: true)]
final class RecordMetadataViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('record', 'array', 'Content record (or any other applicable table row) to check', true);
        $this->registerArgument('as', 'string', 'Optional: variable name to assign the AiMetadata object to');
    }

    public function render(): string|AiMetadata
    {
        $value = $this->arguments['record']['ai_metadata'] ?? null;
        $metadata = new AiMetadata(is_string($value) ? $value : null);

        if ($this->arguments['as'] !== null) {
            // Same convention as f:variable: assign as a side effect and render
            // nothing - used as a standalone tag, the object itself must not end up
            // in the output stream (it would be string-cast and AiMetadata has no
            // __toString()).
            $this->renderingContext->getVariableProvider()->add($this->arguments['as'], $metadata);
            return '';
        }

        return $metadata;
    }
}
