<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Service;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Service\CacheHelper;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

// Covers invalidate()'s actual effect, not just that it calls something -
// addCacheTag() and invalidate() only work together if both sides agree on the
// exact same tag string, and that's precisely the kind of thing that silently
// drifts apart without a test (e.g. a typo'd prefix on one side only).
final class CacheHelperTest extends FunctionalTestCase
{
    private const TEST_CACHE_IDENTIFIER = 'ai_label_cache_helper_test';

    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    // Registered through TYPO3's own cache configuration, merged in before the
    // container boots - not `new TransientMemoryBackend()` directly.
    // AbstractBackend's constructor signature differs between v13.4 (a leading
    // $context string, required) and v14 (options array only), and
    // CacheManager::createCache() already knows which one to call for whichever
    // version is active; going through cacheConfigurations sidesteps that
    // difference entirely instead of version-branching this test.
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    self::TEST_CACHE_IDENTIFIER => [
                        'frontend' => VariableFrontend::class,
                        'backend' => TransientMemoryBackend::class,
                    ],
                ],
            ],
        ],
    ];

    #[Test]
    public function invalidateFlushesOnlyEntriesTaggedForTheGivenMetadataUid(): void
    {
        $cache = $this->get(CacheManager::class)->getCache(self::TEST_CACHE_IDENTIFIER);

        // Tagged exactly as CacheHelper::addCacheTag() would tag a page that
        // rendered metadata uid 42 - the format invalidate() has to match.
        $cache->set('page-showing-metadata-42', 'cached output', ['tx-ai-labels-42']);
        $cache->set('page-showing-metadata-99', 'other cached output', ['tx-ai-labels-99']);

        $this->get(CacheHelper::class)->invalidate(42);

        self::assertFalse($cache->has('page-showing-metadata-42'));
        self::assertTrue($cache->has('page-showing-metadata-99'));
    }
}
