<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\DataProcessing;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\DataProcessing\AiLabelProcessor;
use B13\AiLabel\Domain\Model\AiMetadata;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AiLabelProcessorTest extends FunctionalTestCase
{
    // ai_label composer-requires typo3/cms-filelist - not part of testing-framework's
    // default sysext set, so it must be loaded explicitly or PackageCollection throws.
    protected array $coreExtensionsToLoad = [
        'filelist',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    #[Test]
    public function assignsAiMetadataObjectUnderDefaultVariableName(): void
    {
        $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $cObj->data = [
            'uid' => 1,
            'tx_ailabel_metadata' => '{"origin":1,"reviewed_by":0,"reviewed_timestamp":0}',
        ];

        $processedData = $this->get(AiLabelProcessor::class)->process($cObj, [], [], []);

        self::assertInstanceOf(AiMetadata::class, $processedData['aiMetadata']);
        self::assertTrue($processedData['aiMetadata']->isAiCreated());
        self::assertFalse($processedData['aiMetadata']->isAiModified());
    }

    #[Test]
    public function unflaggedRecordYieldsUnflaggedMetadata(): void
    {
        $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $cObj->data = ['uid' => 1, 'tx_ailabel_metadata' => null];

        $processedData = $this->get(AiLabelProcessor::class)->process($cObj, [], [], []);

        self::assertInstanceOf(AiMetadata::class, $processedData['aiMetadata']);
        self::assertFalse($processedData['aiMetadata']->isFlagged());
    }

    #[Test]
    public function respectsCustomTargetVariableName(): void
    {
        $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $cObj->data = ['uid' => 1, 'tx_ailabel_metadata' => '{"origin":2,"reviewed_by":0,"reviewed_timestamp":0}'];

        $processedData = $this->get(AiLabelProcessor::class)->process($cObj, [], ['as' => 'myVar'], []);

        self::assertInstanceOf(AiMetadata::class, $processedData['myVar']);
        self::assertTrue($processedData['myVar']->isAiModified());
        self::assertArrayNotHasKey('aiMetadata', $processedData);
    }
}
