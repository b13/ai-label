<?php

declare(strict_types=1);

namespace B13\AiLabel\Frontend;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Configuration\ImageMarkerSettings;
use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Enum\ImageMarkerMode;
use B13\AiLabel\Imaging\AiWatermark;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class WatermarkAltTextAppender
{
    private const LABEL_PREFIX = 'LLL:EXT:ai_label/Resources/Private/Language/locallang.xlf:alt.aiLabel.';

    /**
     * @var array<string, ResourceStorage>|null
     */
    private ?array $storagesByPublicPath = null;

    public function __construct(
        private readonly ImageMarkerSettings $settings,
        private readonly AiWatermark $watermark,
        private readonly StorageRepository $storageRepository,
        private readonly ProcessedFileRepository $processedFileRepository,
        private readonly ConnectionPool $connectionPool,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {
    }

    public function appendTo(string $html, ServerRequestInterface $request): string
    {
        if ($this->settings->getMode() !== ImageMarkerMode::Baked || stripos($html, '<img') === false) {
            return $html;
        }

        $originsBySrc = [];
        $languageService = null;

        return (string)preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $match) use (&$originsBySrc, &$languageService, $request): string {
                $tag = $match[0];
                $src = $this->getAttribute($tag, 'src');
                if ($src === null || $src === '') {
                    return $tag;
                }
                if (!array_key_exists($src, $originsBySrc)) {
                    $originsBySrc[$src] = $this->resolveOrigin($src);
                }
                $origin = $originsBySrc[$src];
                if ($origin === null) {
                    return $tag;
                }

                $languageService ??= $this->getLanguageService($request);
                $label = trim($languageService->sL(self::LABEL_PREFIX . ($origin === AiOrigin::Generated ? 'generated' : 'modified')));
                if ($label === '') {
                    return $tag;
                }

                return $this->withLabeledAlt($tag, $label);
            },
            $html
        );
    }

    private function withLabeledAlt(string $tag, string $label): string
    {
        $alt = $this->getAttribute($tag, 'alt');
        $decodedAlt = $alt === null ? '' : html_entity_decode($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_contains($decodedAlt, $label)) {
            return $tag;
        }

        $attribute = 'alt="' . htmlspecialchars(trim($decodedAlt . ' ' . $label), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        if ($alt === null) {
            return (string)preg_replace('/^<img\b/i', '<img ' . $attribute, $tag);
        }

        return (string)preg_replace_callback(
            '/(?<=\s)alt\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)/i',
            static fn (): string => $attribute,
            $tag,
            1
        );
    }

    private function getAttribute(string $tag, string $name): ?string
    {
        if (!preg_match('/\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $tag, $match)) {
            return null;
        }

        return ($match[1] ?? '') . ($match[2] ?? '') . ($match[3] ?? '');
    }

    private function resolveOrigin(string $src): ?AiOrigin
    {
        $path = parse_url(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $path = '/' . ltrim(rawurldecode($path), '/');

        foreach ($this->getStoragesByPublicPath() as $publicPath => $storage) {
            if (!str_starts_with($path, $publicPath)) {
                continue;
            }
            $uid = $this->connectionPool->getConnectionForTable('sys_file_processedfile')->fetchOne(
                'SELECT uid FROM sys_file_processedfile WHERE storage = ? AND identifier = ?',
                [$storage->getUid(), '/' . substr($path, strlen($publicPath))]
            );
            if ($uid !== false) {
                return $this->watermark->getWatermarkOrigin($this->processedFileRepository->findByUid((int)$uid));
            }
        }

        return null;
    }

    /**
     * @return array<string, ResourceStorage>
     */
    private function getStoragesByPublicPath(): array
    {
        if ($this->storagesByPublicPath !== null) {
            return $this->storagesByPublicPath;
        }

        $this->storagesByPublicPath = [];
        foreach ($this->storageRepository->findAll() as $storage) {
            if (!$storage->isOnline() || !$storage->isPublic()) {
                continue;
            }
            try {
                $publicUrl = $storage->getPublicUrl($storage->getRootLevelFolder(false));
            } catch (\Throwable) {
                continue;
            }
            $publicPath = is_string($publicUrl) ? parse_url($publicUrl, PHP_URL_PATH) : null;
            if (!is_string($publicPath) || $publicPath === '') {
                continue;
            }
            $this->storagesByPublicPath['/' . trim(rawurldecode($publicPath), '/') . '/'] = $storage;
        }
        uksort($this->storagesByPublicPath, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $this->storagesByPublicPath;
    }

    private function getLanguageService(ServerRequestInterface $request): LanguageService
    {
        $language = $request->getAttribute('language');
        if ($language instanceof SiteLanguage) {
            return $this->languageServiceFactory->createFromSiteLanguage($language);
        }

        return $this->languageServiceFactory->create('default');
    }
}
