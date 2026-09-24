<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Frontend;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Frontend\WatermarkAltTextAppender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class WatermarkAltTextAppenderTest extends FunctionalTestCase
{
    private const GENERATED = '/fileadmin/_processed_/1/a/csm_generated_aaaaaaaaaa.jpg';
    private const MODIFIED = '/fileadmin/_processed_/2/b/csm_modified_bbbbbbbbbb.jpg';
    private const PLAIN = '/fileadmin/_processed_/3/c/csm_plain_cccccccccc.jpg';
    private const TOO_NARROW = '/fileadmin/_processed_/1/a/csm_generated_dddddddddd.jpg';
    private const ORIGINAL = '/fileadmin/generated.jpg';

    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'ai_label' => [
                'imageMarker' => 'baked',
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/WatermarkedImages.csv');
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_enabled'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'ImageMagick';
    }

    public static function labeledImagesDataProvider(): array
    {
        return [
            'existing alt gets the generated label appended' => [
                '<img src="' . self::GENERATED . '" alt="A lake at dawn" />',
                '<img src="' . self::GENERATED . '" alt="A lake at dawn (AI generated)" />',
            ],
            'existing alt gets the modified label appended' => [
                '<img src="' . self::MODIFIED . '" alt="A lake at dawn">',
                '<img src="' . self::MODIFIED . '" alt="A lake at dawn (AI modified)">',
            ],
            'empty alt becomes the label' => [
                '<img alt="" src="' . self::GENERATED . '">',
                '<img alt="(AI generated)" src="' . self::GENERATED . '">',
            ],
            'missing alt is added' => [
                '<img src="' . self::GENERATED . '" width="600">',
                '<img alt="(AI generated)" src="' . self::GENERATED . '" width="600">',
            ],
            'absolute url is resolved' => [
                '<img src="https://example.com' . self::GENERATED . '?v=1" alt="Lake">',
                '<img src="https://example.com' . self::GENERATED . '?v=1" alt="Lake (AI generated)">',
            ],
            'encoded alt stays encoded' => [
                '<img src="' . self::GENERATED . '" alt="Tom &amp; Jerry &quot;live&quot;">',
                '<img src="' . self::GENERATED . '" alt="Tom &amp; Jerry &quot;live&quot; (AI generated)">',
            ],
            'img inside picture is labeled, sources are left alone' => [
                '<picture><source srcset="' . self::GENERATED . '"><img class="x" src="' . self::GENERATED . '" alt="Lake"></picture>',
                '<picture><source srcset="' . self::GENERATED . '"><img class="x" src="' . self::GENERATED . '" alt="Lake (AI generated)"></picture>',
            ],
        ];
    }

    #[Test]
    #[DataProvider('labeledImagesDataProvider')]
    public function watermarkedImageGetsTheLabelInItsAltText(string $html, string $expected): void
    {
        self::assertSame($expected, $this->get(WatermarkAltTextAppender::class)->appendTo($html, $this->createRequest()));
    }

    public static function untouchedImagesDataProvider(): array
    {
        return [
            'unflagged image' => ['<img src="' . self::PLAIN . '" alt="Lake">'],
            'variant too narrow to carry the badge' => ['<img src="' . self::TOO_NARROW . '" alt="Lake">'],
            'original file served directly' => ['<img src="' . self::ORIGINAL . '" alt="Lake">'],
            'unknown processed file' => ['<img src="/fileadmin/_processed_/9/9/csm_unknown.jpg" alt="Lake">'],
            'external image' => ['<img src="https://cdn.example.com/lake.jpg" alt="Lake">'],
            'no img at all' => ['<p>No image here</p>'],
        ];
    }

    #[Test]
    #[DataProvider('untouchedImagesDataProvider')]
    public function imageWithoutWatermarkKeepsItsAltText(string $html): void
    {
        self::assertSame($html, $this->get(WatermarkAltTextAppender::class)->appendTo($html, $this->createRequest()));
    }

    #[Test]
    public function nothingIsLabeledOutsideBakedMode(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = 'overlay';
        $html = '<img src="' . self::GENERATED . '" alt="Lake">';

        self::assertSame($html, $this->get(WatermarkAltTextAppender::class)->appendTo($html, $this->createRequest()));
    }

    #[Test]
    public function labelIsNeverAppendedTwice(): void
    {
        $appender = $this->get(WatermarkAltTextAppender::class);
        $once = $appender->appendTo('<img src="' . self::GENERATED . '" alt="Lake">', $this->createRequest());

        self::assertSame($once, $appender->appendTo($once, $this->createRequest()));
    }

    #[Test]
    public function labelFollowsTheSiteLanguage(): void
    {
        $html = '<img src="' . self::GENERATED . '" alt="See"><img src="' . self::MODIFIED . '" alt="See">';

        self::assertSame(
            '<img src="' . self::GENERATED . '" alt="See (KI generiert)"><img src="' . self::MODIFIED . '" alt="See (KI modifiziert)">',
            $this->get(WatermarkAltTextAppender::class)->appendTo($html, $this->createRequest('de_DE.UTF-8'))
        );
    }

    private function createRequest(string $locale = 'en_US.UTF-8'): ServerRequest
    {
        return (new ServerRequest('https://example.com/'))
            ->withAttribute('language', new SiteLanguage(0, $locale, new Uri('https://example.com/'), []));
    }
}
