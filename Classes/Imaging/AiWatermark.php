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

use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Model\AiMetadata;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Imaging\ImageMagickFile;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Type\File\ImageInfo;
use TYPO3\CMS\Core\Utility\CommandUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Composites the AI badge into the pixels of an already-processed image. Only ever
// touches the ProcessedFile - ProcessedFile::updateWithLocalFile() writes through
// ResourceStorage::updateProcessedFile(), which always targets the storage's
// processing folder, so the editor's original asset is never modified.
//
// The badge is the same artwork the frontend partial uses, pre-rasterised to PNG
// (Resources/Public/Icons/*.png next to the SVGs). ImageMagick can only read SVG
// through an rsvg/inkscape delegate that is not present on many hosts, so shipping
// the raster version is what makes this work anywhere at all.
final class AiWatermark implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    // Raster formats ImageMagick can composite onto. SVG is deliberately absent: it is
    // handled by core's SvgImageProcessor, which never rasterises, so there are no
    // pixels to write into.
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'];

    // The badge is drawn at a constant width and is never enlarged beyond it. Scaling it
    // with the image (the previous behaviour) meant the same picture carried a 60px marker
    // as a thumbnail and a 320px one as a hero - the marker visibly zoomed in with the
    // image instead of staying a discreet, consistent mark.
    //
    // 160px is picked to line up with the "overlay" mode: that renders at 83 CSS px, and a
    // processed variant is typically displayed at about half its pixel width, so both modes
    // end up looking roughly the same size on the page.
    private const TARGET_WIDTH = 160;

    // ...with the one exception of images too small to carry a 160px badge, where a constant
    // width would swallow the picture. Only there does it shrink along with the image.
    private const MAX_RELATIVE_WIDTH = 0.25;

    private const MARGIN_RATIO = 0.03;
    private const MAX_MARGIN = 24;

    // Below this the badge would be unreadable anyway, and a mark nobody can read
    // discloses nothing - such images fall back to the content element marker.
    private const MIN_IMAGE_WIDTH = 160;

    public function appliesTo(FileInterface $sourceFile): bool
    {
        if (!$this->isProcessingAvailable()) {
            return false;
        }
        if (!in_array(strtolower($sourceFile->getExtension()), self::SUPPORTED_EXTENSIONS, true)) {
            return false;
        }

        return $this->getOrigin($sourceFile) !== AiOrigin::Human;
    }

    public function applyTo(ProcessedFile $processedFile, FileInterface $sourceFile): void
    {
        $origin = $this->getOrigin($sourceFile);
        if ($origin === AiOrigin::Human) {
            return;
        }

        $badgeFile = $this->getBadgeFile($origin);
        if ($badgeFile === null) {
            return;
        }

        // true = writable copy. For a processed file that "uses the original file"
        // (nothing to scale/crop, so core points it at the original) this hands back a
        // copy, never the original itself - so compositing onto it is safe either way.
        $sourceForProcessing = $processedFile->getForLocalProcessing(true);
        $imageInfo = GeneralUtility::makeInstance(ImageInfo::class, $sourceForProcessing);
        $width = $imageInfo->getWidth();
        if ($width < self::MIN_IMAGE_WIDTH) {
            return;
        }

        $targetFile = GeneralUtility::tempnam('ai_label_watermark_', '.' . $processedFile->getExtension());
        if (!$this->composite($sourceForProcessing, $badgeFile, $targetFile, $width)) {
            GeneralUtility::unlink_tempfile($targetFile);
            return;
        }

        $watermarkedInfo = GeneralUtility::makeInstance(ImageInfo::class, $targetFile);
        $processedFile->updateProperties([
            'width' => $watermarkedInfo->getWidth(),
            'height' => $watermarkedInfo->getHeight(),
            'size' => $watermarkedInfo->getSize(),
        ]);
        $processedFile->updateWithLocalFile($targetFile);
        GeneralUtility::unlink_tempfile($targetFile);
    }

    /**
     * The sizing rule: a constant TARGET_WIDTH, never exceeded, so the badge cannot grow
     * with the image - only pictures too small to carry it shrink the badge, and then to
     * at most MAX_RELATIVE_WIDTH of their own width.
     *
     * Public because it is the one piece of behaviour here that can be asserted without
     * an image processor installed, and it has regressed once already.
     */
    public function getBadgeWidth(int $imageWidth): int
    {
        return min(self::TARGET_WIDTH, (int)round($imageWidth * self::MAX_RELATIVE_WIDTH));
    }

    private function composite(string $sourceFile, string $badgeFile, string $targetFile, int $imageWidth): bool
    {
        $badgeWidth = $this->getBadgeWidth($imageWidth);
        $margin = min(self::MAX_MARGIN, max(4, (int)round($imageWidth * self::MARGIN_RATIO)));

        // The parenthesised group scales the badge before it is composited, so the
        // badge keeps its own aspect ratio independently of the image below it.
        // The ">" flag means "shrink only, never enlarge", so the badge can never be
        // blown up past the resolution of its own artwork - it has to be escaped, or
        // the shell would read it as an output redirection.
        $resize = CommandUtility::escapeShellArgument($badgeWidth . 'x>');
        $parameters = ImageMagickFile::fromFilePath($sourceFile)
            . ' \( ' . ImageMagickFile::fromFilePath($badgeFile) . ' -resize ' . $resize . ' \)'
            . ' -gravity SouthEast -geometry +' . $margin . '+' . $margin . ' -composite'
            . ' ' . CommandUtility::escapeShellArgument($targetFile);

        $command = CommandUtility::imageMagickCommand('convert', $parameters);
        // Both are by-reference parameters, and $returnValue is typed non-nullable
        // (int &$returnValue = 0) - passing undefined variables is a TypeError.
        $output = [];
        $returnValue = 0;
        CommandUtility::exec($command, $output, $returnValue);

        if ($returnValue !== 0 || !file_exists($targetFile) || filesize($targetFile) === 0) {
            $this->logger?->warning('Could not composite the AI watermark onto {file}.', [
                'file' => $sourceFile,
                'returnValue' => $returnValue,
                'output' => $output,
            ]);
            return false;
        }

        GeneralUtility::fixPermissions($targetFile);
        return true;
    }

    private function getOrigin(FileInterface $file): AiOrigin
    {
        // Files never touched by this extension have no such property at all, and
        // getProperty() throws for unknown keys.
        if (!$file->hasProperty('tx_ailabel_metadata')) {
            return AiOrigin::Human;
        }
        $value = $file->getProperty('tx_ailabel_metadata');

        return AiMetadata::fromJsonString(is_string($value) ? $value : null)->getOrigin();
    }

    private function getBadgeFile(AiOrigin $origin): ?string
    {
        $name = match ($origin) {
            AiOrigin::Generated => 'ai_generated_black.png',
            AiOrigin::Manipulated => 'ai_modified_black.png',
            AiOrigin::Human => null,
        };
        if ($name === null) {
            return null;
        }

        $absolutePath = GeneralUtility::getFileAbsFileName('EXT:ai_label/Resources/Public/Icons/' . $name);

        return $absolutePath !== '' && file_exists($absolutePath) ? $absolutePath : null;
    }

    // GraphicsMagick's "convert" has no -composite operator (it offers a separate
    // "gm composite" binary with a different, mask-based signature), so the baked mode
    // is ImageMagick-only. Sites on GraphicsMagick keep the content element marker.
    private function isProcessingAvailable(): bool
    {
        return (bool)($GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_enabled'] ?? false)
            && ($GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] ?? '') === 'ImageMagick';
    }
}
