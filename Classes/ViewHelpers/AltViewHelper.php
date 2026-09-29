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

use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Imaging\AiWatermark;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

#[Autoconfigure(public: true)]
final class AltViewHelper extends AbstractViewHelper
{
    public function __construct(private readonly AiWatermark $watermark)
    {
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('file', FileInterface::class, 'The image (file or file reference)', true);
    }

    public function render(): string
    {
        /** @var FileInterface $file */
        $file = $this->arguments['file'];
        $alt = trim($this->getAlternative($file));

        $origin = $this->watermark->getBakedOrigin($file);
        if ($origin === null) {
            return $alt;
        }

        $label = trim((string)LocalizationUtility::translate(
            'alt.aiLabel.' . ($origin === AiOrigin::Generated ? 'generated' : 'modified'),
            'ai_label'
        ));
        if ($label === '' || str_contains($alt, $label)) {
            return $alt;
        }

        return trim($alt . ' ' . $label);
    }

    private function getAlternative(FileInterface $file): string
    {
        if ($file instanceof FileReference) {
            return (string)$file->getAlternative();
        }

        return $file->hasProperty('alternative') ? (string)$file->getProperty('alternative') : '';
    }
}
