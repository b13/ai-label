<?php

declare(strict_types=1);

namespace B13\AiLabel\Service;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Resource\FileReference;

final class CacheHelper
{
    private const PREFIX = 'tx-ai-labels';

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {
    }

    public function invalidate(int $metadataUid): void
    {
        $this->cacheManager->flushCachesByTag(self::PREFIX . '-' . $metadataUid);
    }

    public function addCacheTag(FileReference $file): void
    {
        $metadataUid = $file->hasProperty('metadata_uid') ? (int)$file->getProperty('metadata_uid') : 0;
        if ($metadataUid <= 0) {
            return;
        }
        $request = $this->getServerRequestInterface();
        if ($request === null) {
            return;
        }
        /** @var ?CacheDataCollector $cacheDataCollector */
        $cacheDataCollector = $request->getAttribute('frontend.cache.collector');
        if ($cacheDataCollector === null) {
            return;
        }
        $cacheDataCollector->addCacheTags(new CacheTag(self::PREFIX . '-' . $metadataUid));
    }

    private function getServerRequestInterface(): ?ServerRequestInterface
    {
        return $GLOBALS['TYPO3_REQUEST'] ?? null;
    }
}
