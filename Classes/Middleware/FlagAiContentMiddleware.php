<?php

declare(strict_types=1);

namespace B13\AiLabel\Middleware;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Service\AiLabelApi;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Middleware\AiMiddlewareInterface;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Response\ConversationResponse;
use B13\Aim\Response\StreamChunkIterator;
use B13\Aim\Response\TextResponse;
use B13\Aim\Response\ToolCallingResponse;
use Psr\Log\LoggerInterface;

/**
 * Optional integration with EXT:aim (b13/aim): flags a record as
 * AI-created/AI-modified via AiLabelApi once a request through aim's
 * middleware pipeline succeeds, purely opt-in via the request's own
 * metadata. AiM has no way to know which record a response's content will
 * end up in on its own (that's decided by whatever the calling extension
 * does with $response->content afterwards), so this is opt-in per call:
 *
 *   $ai->text()->prompt(...)->withMetadata([
 *       'aiLabel' => ['table' => 'sys_file_metadata', 'uid' => $uid, 'origin' => 'created'],
 *   ])->send();
 *
 * 'origin' is 'created' (brand-new AI content, the default if omitted) or
 * 'modified' (existing human content AI-edited), mirrors AiOrigin. Writing
 * through AiLabelApi requires a backend user to attribute the change to
 * (its own resolveUser()); calls made outside a backend context (frontend,
 * CLI/scheduler) will fail that check, which this middleware treats as a
 * non-fatal, logged no-op rather than surfacing it as an aim error.
 *
 * Only ever instantiated when b13/aim is actually installed: this class is
 * excluded from Services.yaml's normal `resource: '../Classes/*'` scan, so
 * Symfony's container compilation never reflects (and therefore never needs
 * to resolve AiMiddlewareInterface for) it when aim isn't present.
 * Configuration/Services.php registers and tags it manually instead, gated
 * behind a runtime class_exists() check.
 *
 * Priority -850 there, chosen the same way as any other aim
 * middleware: after CostTrackingMiddleware (-800) so the request is fully
 * settled, before EventDispatchMiddleware (-900) / CoreDispatchMiddleware
 * (-1000) so this only ever runs for genuinely successful responses.
 */
final class FlagAiContentMiddleware implements AiMiddlewareInterface
{
    public function __construct(
        private readonly AiLabelApi $aiLabelApi,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        $target = $this->resolveTarget($request);
        if ($target === null) {
            return $next->handle($request, $provider, $configuration);
        }

        $response = $next->handle($request, $provider, $configuration);

        if (($response instanceof ConversationResponse || $response instanceof ToolCallingResponse) && $response->isStreaming()) {
            $this->deferLabel($response, $target);
            return $response;
        }

        if ($response->isSuccessful()) {
            $this->applyLabel($target);
        }

        return $response;
    }

    /**
     * @return array{table: string, uid: int, origin: string}|null
     */
    private function resolveTarget(AiRequestInterface $request): ?array
    {
        if (!property_exists($request, 'metadata') || !is_array($request->metadata)) {
            return null;
        }

        $raw = $request->metadata['aiLabel'] ?? null;
        if (!is_array($raw) || !isset($raw['table'], $raw['uid'])) {
            return null;
        }

        $table = (string)$raw['table'];
        $uid = (int)$raw['uid'];
        if ($table === '' || $uid <= 0) {
            return null;
        }

        $origin = (string)($raw['origin'] ?? 'created');
        if (!in_array($origin, ['created', 'modified'], true)) {
            $origin = 'created';
        }

        return ['table' => $table, 'uid' => $uid, 'origin' => $origin];
    }

    /**
     * @param array{table: string, uid: int, origin: string} $target
     */
    private function deferLabel(ConversationResponse|ToolCallingResponse $response, array $target): void
    {
        $streamIterator = $response->streamIterator;
        if (!$streamIterator instanceof StreamChunkIterator) {
            return;
        }

        register_shutdown_function(
            function () use ($streamIterator, $target): void {
                $this->applyLabelForDrainedStream($streamIterator, $target);
            },
        );
    }

    /**
     * What the shutdown function registered by deferLabel() actually calls.
     * Split out so a test can invoke it directly against a manually-drained
     * iterator - PHP only invokes shutdown functions at the end of the
     * whole test process, not per test.
     *
     * @param array{table: string, uid: int, origin: string} $target
     */
    private function applyLabelForDrainedStream(StreamChunkIterator $streamIterator, array $target): void
    {
        if ($streamIterator->getAccumulatedContent() === '') {
            return;
        }
        $this->applyLabel($target);
    }

    /**
     * @param array{table: string, uid: int, origin: string} $target
     */
    private function applyLabel(array $target): void
    {
        try {
            if ($target['origin'] === 'modified') {
                $this->aiLabelApi->aiModified($target['table'], $target['uid']);
            } else {
                $this->aiLabelApi->aiCreated($target['table'], $target['uid']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to flag {table}:{uid} via AiLabelApi: {message}', [
                'table' => $target['table'],
                'uid' => $target['uid'],
                'message' => $e->getMessage(),
            ]);
        }
    }
}
