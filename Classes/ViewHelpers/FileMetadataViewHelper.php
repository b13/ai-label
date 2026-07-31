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
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Resolves the AiMetadata object for a FAL file reference - no markup, no
 * decision-making, just the same domain object used everywhere else in this
 * extension, handed through to the frontend template.
 *
 * Returns the object itself, so it can be used inline...
 *
 * ```
 *    <f:variable name="aiMetadata" value="{ailabel:fileMetadata(fileReference: image)}" />
 * ```
 *
 * ...or assign it directly via the optional "as" argument:
 *
 * ```
 *    <ailabel:fileMetadata fileReference="{image}" as="aiMetadata" />
 * ```
 */
#[Autoconfigure(public: true)]
final class FileMetadataViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('fileReference', FileReference::class, 'A FAL file reference to check', true);
        $this->registerArgument('as', 'string', 'Optional: variable name to assign the AiMetadata object to');
    }

    public function render(): string|AiMetadata
    {
        /** @var FileReference $fileReference */
        $fileReference = $this->arguments['fileReference'];
        // sys_file_metadata rows don't strictly have to carry ai_metadata (e.g. files
        // never touched by this extension) - getProperty() throws for unknown keys.
        $value = $fileReference->hasProperty('ai_metadata') ? $fileReference->getProperty('ai_metadata') : null;
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
