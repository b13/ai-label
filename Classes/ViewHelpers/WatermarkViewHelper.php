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

use B13\AiLabel\Imaging\AiWatermark;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * The badge settings of a file as AiWatermark burns them in: per-file override
 * before the global default.
 */
#[Autoconfigure(public: true)]
final class WatermarkViewHelper extends AbstractViewHelper
{
    // The variants the bundled icon set ships - only these follow the file's color,
    // a project's own variant has no guaranteed black/white counterpart.
    private const COLOR_VARIANTS = ['black', 'black_transparent', 'white', 'white_transparent'];

    public function __construct(private readonly AiWatermark $watermark)
    {
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('file', FileInterface::class, 'The image (file or file reference)', true);
        $this->registerArgument('variant', 'string', 'The variant the template asked for, resolved into "variant"');
    }

    /**
     * @return array{baked: bool, color: string, position: string, width: int, variant: string}
     */
    public function render(): array
    {
        /** @var FileInterface $file */
        $file = $this->arguments['file'];

        return [
            'baked' => $this->watermark->getBakedOrigin($file) !== null,
            'color' => $this->watermark->getColor($file)->value,
            'position' => $this->watermark->getPosition($file)->value,
            'width' => $this->watermark->getWidth($file)->value,
            'variant' => $this->resolveVariant($file, (string)($this->arguments['variant'] ?? '')),
        ];
    }

    // An editor's per-file color wins over the template's variant (keeping its
    // transparency), which wins over the global color.
    private function resolveVariant(FileInterface $file, string $variant): string
    {
        if ($variant !== '' && !in_array($variant, self::COLOR_VARIANTS, true)) {
            return $variant;
        }

        $color = $this->watermark->getWatermarkOverride($file)->getColor();
        if ($color !== null) {
            return $color->value . (str_ends_with($variant, '_transparent') ? '_transparent' : '');
        }

        return $variant !== '' ? $variant : $this->watermark->getColor($file)->value;
    }
}
