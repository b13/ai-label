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
}
