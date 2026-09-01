<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Configuration;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Configuration\ImageMarkerSettings;
use B13\AiLabel\Domain\Enum\ImageMarkerMode;
use B13\AiLabel\Domain\Enum\WatermarkColor;
use B13\AiLabel\Domain\Enum\WatermarkPosition;
use B13\AiLabel\Domain\Enum\WatermarkWidth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ImageMarkerSettingsTest extends FunctionalTestCase
{
    // ai_label composer-requires typo3/cms-filelist and typo3/cms-fluid-styled-content -
    // neither is part of testing-framework's default sysext set, so both must be loaded
    // explicitly or PackageCollection throws.
    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    public static function modeDataProvider(): array
    {
        return [
            'off' => ['off', ImageMarkerMode::Off],
            'overlay' => ['overlay', ImageMarkerMode::Overlay],
            'baked' => ['baked', ImageMarkerMode::Baked],
        ];
    }

    #[Test]
    #[DataProvider('modeDataProvider')]
    public function configuredModeIsResolved(string $configured, ImageMarkerMode $expected): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = $configured;
        self::assertSame($expected, $this->get(ImageMarkerSettings::class)->getMode());
    }

    /**
     * The whole feature is opt-in: anything unexpected has to leave the extension behaving
     * exactly as it did before the setting existed, never silently rewrite images.
     */
    #[Test]
    public function anUnknownModeFallsBackToOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = 'something-else';
        self::assertSame(ImageMarkerMode::Off, $this->get(ImageMarkerSettings::class)->getMode());
    }

    #[Test]
    public function anEmptyModeFallsBackToOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = '';
        self::assertSame(ImageMarkerMode::Off, $this->get(ImageMarkerSettings::class)->getMode());
    }

    #[Test]
    public function aMissingConfigurationFallsBackToOff(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']);
        self::assertSame(ImageMarkerMode::Off, $this->get(ImageMarkerSettings::class)->getMode());
    }

    public static function watermarkPositionDataProvider(): array
    {
        return [
            'top-left' => ['top-left', WatermarkPosition::TopLeft],
            'top-right' => ['top-right', WatermarkPosition::TopRight],
            'bottom-left' => ['bottom-left', WatermarkPosition::BottomLeft],
            'bottom-right' => ['bottom-right', WatermarkPosition::BottomRight],
        ];
    }

    #[Test]
    #[DataProvider('watermarkPositionDataProvider')]
    public function configuredWatermarkPositionIsResolved(string $configured, WatermarkPosition $expected): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkPosition'] = $configured;
        self::assertSame($expected, $this->get(ImageMarkerSettings::class)->getWatermarkPosition());
    }

    // Default must stay unchanged: the badge always sat bottom right before this setting existed.
    #[Test]
    public function anUnknownWatermarkPositionFallsBackToBottomRight(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkPosition'] = 'something-else';
        self::assertSame(WatermarkPosition::BottomRight, $this->get(ImageMarkerSettings::class)->getWatermarkPosition());
    }

    #[Test]
    public function aMissingWatermarkPositionConfigurationFallsBackToBottomRight(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']);
        self::assertSame(WatermarkPosition::BottomRight, $this->get(ImageMarkerSettings::class)->getWatermarkPosition());
    }

    public static function watermarkColorDataProvider(): array
    {
        return [
            'black' => ['black', WatermarkColor::Black],
            'white' => ['white', WatermarkColor::White],
        ];
    }

    #[Test]
    #[DataProvider('watermarkColorDataProvider')]
    public function configuredWatermarkColorIsResolved(string $configured, WatermarkColor $expected): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkColor'] = $configured;
        self::assertSame($expected, $this->get(ImageMarkerSettings::class)->getWatermarkColor());
    }

    // Default must stay unchanged: the badge was always black before this setting existed.
    #[Test]
    public function anUnknownWatermarkColorFallsBackToBlack(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkColor'] = 'something-else';
        self::assertSame(WatermarkColor::Black, $this->get(ImageMarkerSettings::class)->getWatermarkColor());
    }

    #[Test]
    public function aMissingWatermarkColorConfigurationFallsBackToBlack(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']);
        self::assertSame(WatermarkColor::Black, $this->get(ImageMarkerSettings::class)->getWatermarkColor());
    }

    public static function watermarkWidthDataProvider(): array
    {
        return [
            'regular' => ['160', WatermarkWidth::Regular],
            'small' => ['80', WatermarkWidth::Small],
        ];
    }

    #[Test]
    #[DataProvider('watermarkWidthDataProvider')]
    public function configuredWatermarkWidthIsResolved(string $configured, WatermarkWidth $expected): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkWidth'] = $configured;
        self::assertSame($expected, $this->get(ImageMarkerSettings::class)->getWatermarkWidth());
    }

    // Default must stay unchanged: the badge was always a constant 160px before this
    // became configurable - a closed set of two options, so anything outside {160, 80}
    // (non-numeric, or a number that just isn't one of the two allowed sizes) falls
    // back the same way an unrecognised position/color would.
    #[Test]
    public function aNonNumericWatermarkWidthFallsBackToRegular(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkWidth'] = 'something-else';
        self::assertSame(WatermarkWidth::Regular, $this->get(ImageMarkerSettings::class)->getWatermarkWidth());
    }

    #[Test]
    public function aNumericButUnsupportedWatermarkWidthFallsBackToRegular(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkWidth'] = '400';
        self::assertSame(WatermarkWidth::Regular, $this->get(ImageMarkerSettings::class)->getWatermarkWidth());
    }

    #[Test]
    public function aMissingWatermarkWidthConfigurationFallsBackToRegular(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']);
        self::assertSame(WatermarkWidth::Regular, $this->get(ImageMarkerSettings::class)->getWatermarkWidth());
    }
}
