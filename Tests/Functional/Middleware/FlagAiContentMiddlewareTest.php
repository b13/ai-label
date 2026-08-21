<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Middleware;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Middleware\FlagAiContentMiddleware;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\ConversationResponse;
use B13\Aim\Response\StreamChunkIterator;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * b13/aim is a real require-dev dependency here (unlike aim's own test
 * suite, which can only ever exercise the "ai_label not installed" no-op
 * path for this integration): with both extensions loaded,
 * FlagAiContentMiddleware is fetched straight from the container, proving
 * the whole conditional-registration setup in Configuration/Services.php
 * actually wires it up - not just that its own process() logic is correct
 * in isolation. Uses a real tt_content row + a real backend user throughout
 * (rather than a fake AiLabelApi, the way aim's own removed test had to),
 * so these also cover the real DataHandler round-trip.
 */
final class FlagAiContentMiddlewareTest extends FunctionalTestCase
{
    // ai_label composer-requires typo3/cms-filelist and typo3/cms-fluid-styled-content -
    // neither is part of testing-framework's default sysext set, so both must be loaded
    // explicitly or PackageCollection throws.
    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/aim',
        'typo3conf/ext/ai_label',
    ];

    private BackendUserAuthentication $backendUser;
    private int $contentUid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Service/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Service/Fixtures/pages.csv');
        $this->backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['pid' => 1, 'header' => 'A teaser', 'CType' => 'text']);
        $this->contentUid = (int)$content->lastInsertId();
    }

    private function createConfig(): ProviderConfiguration
    {
        return new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'openai',
            'title' => 'Test',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
        ]);
    }

    /** @return array{table: string, uid: int, origin?: string} */
    private function aiLabelTarget(string $origin = 'created'): array
    {
        return ['table' => 'tt_content', 'uid' => $this->contentUid, 'origin' => $origin];
    }

    #[Test]
    public function isRegisteredAsAPublicServiceOnceAimIsInstalled(): void
    {
        // The actual point of this whole test class: proves Configuration/
        // Services.php's class_exists() guard resolved true and registered
        // the service, not just that the class itself is syntactically fine.
        self::assertInstanceOf(FlagAiContentMiddleware::class, $this->get(FlagAiContentMiddleware::class));
    }

    #[Test]
    public function flagsRecordAsAiCreatedForASuccessfulResponse(): void
    {
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => $this->aiLabelTarget('created'),
        ]);
        $response = new TextResponse('hello');
        $next = new AiMiddlewareHandler(static fn () => $response);

        $this->get(FlagAiContentMiddleware::class)->process($request, self::createStub(AiProviderInterface::class), $config, $next);

        self::assertSame(['origin' => 1, 'reviewed_by' => 0, 'reviewed_timestamp' => 0], $this->fetchMetadata());
    }

    #[Test]
    public function flagsRecordAsAiModifiedWhenOriginIsModified(): void
    {
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => $this->aiLabelTarget('modified'),
        ]);
        $response = new TextResponse('hello');
        $next = new AiMiddlewareHandler(static fn () => $response);

        $this->get(FlagAiContentMiddleware::class)->process($request, self::createStub(AiProviderInterface::class), $config, $next);

        self::assertSame(['origin' => 2, 'reviewed_by' => 0, 'reviewed_timestamp' => 0], $this->fetchMetadata());
    }

    #[Test]
    public function doesNotCallAiLabelApiWhenResponseFailed(): void
    {
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => $this->aiLabelTarget(),
        ]);
        $response = new TextResponse('', errors: ['boom']);
        $next = new AiMiddlewareHandler(static fn () => $response);

        $result = $this->get(FlagAiContentMiddleware::class)->process($request, self::createStub(AiProviderInterface::class), $config, $next);

        self::assertSame($response, $result);
        self::assertNull(
            $this->getConnectionPool()->getConnectionForTable('tt_content')
                ->select(['tx_ailabel_metadata'], 'tt_content', ['uid' => $this->contentUid])->fetchOne(),
        );
    }

    #[Test]
    public function doesNotCallAiLabelApiWhenNoMetadataIsSet(): void
    {
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi');
        $response = new TextResponse('hello');
        $next = new AiMiddlewareHandler(static fn () => $response);

        $result = $this->get(FlagAiContentMiddleware::class)->process($request, self::createStub(AiProviderInterface::class), $config, $next);

        self::assertSame($response, $result);
    }

    #[Test]
    public function doesNotCallAiLabelApiSynchronouslyForAnUndrainedStreamingResponse(): void
    {
        $config = $this->createConfig();
        $request = new ConversationRequest(
            configuration: $config,
            messages: [new UserMessage('Hi')],
            stream: true,
            metadata: ['aiLabel' => $this->aiLabelTarget()],
        );
        $streamIterator = new StreamChunkIterator((function (): \Generator {
            yield 'chunk';
        })(), $config);
        $response = new ConversationResponse('', streamIterator: $streamIterator);
        $next = new AiMiddlewareHandler(static fn () => $response);

        $this->get(FlagAiContentMiddleware::class)->process($request, self::createStub(AiProviderInterface::class), $config, $next);

        self::assertNull(
            $this->getConnectionPool()->getConnectionForTable('tt_content')
                ->select(['tx_ailabel_metadata'], 'tt_content', ['uid' => $this->contentUid])->fetchOne(),
        );
    }

    #[Test]
    public function appliesLabelOffTheDrainedIteratorOnceStreamIsConsumed(): void
    {
        // applyLabelForDrainedStream() is what the shutdown function
        // registered by deferLabel() actually calls - tested directly here
        // since PHP only invokes shutdown functions at the end of the whole
        // test process.
        $config = $this->createConfig();
        $streamIterator = new StreamChunkIterator((function (): \Generator {
            yield 'the accumulated content';
        })(), $config);
        iterator_to_array($streamIterator, false);

        $middleware = $this->get(FlagAiContentMiddleware::class);
        (new \ReflectionMethod($middleware, 'applyLabelForDrainedStream'))->invoke($middleware, $streamIterator, $this->aiLabelTarget());

        self::assertSame(['origin' => 1, 'reviewed_by' => 0, 'reviewed_timestamp' => 0], $this->fetchMetadata());
    }

    #[Test]
    public function doesNotApplyLabelWhenDrainedIteratorHasNoAccumulatedContent(): void
    {
        $config = $this->createConfig();
        $streamIterator = new StreamChunkIterator((function (): \Generator {
            if (false) {
                yield '';
            }
        })(), $config);
        iterator_to_array($streamIterator, false);

        $middleware = $this->get(FlagAiContentMiddleware::class);
        (new \ReflectionMethod($middleware, 'applyLabelForDrainedStream'))->invoke($middleware, $streamIterator, $this->aiLabelTarget());

        self::assertNull(
            $this->getConnectionPool()->getConnectionForTable('tt_content')
                ->select(['tx_ailabel_metadata'], 'tt_content', ['uid' => $this->contentUid])->fetchOne(),
        );
    }

    #[Test]
    public function aFailedAiLabelApiCallDoesNotBreakTheResponse(): void
    {
        // No backend user at all in this specific case (unlike every other
        // test here) - AiLabelApi::aiCreated() throws internally
        // (resolveUser()) - the middleware must swallow that, not let it
        // bubble up as an aim error.
        unset($GLOBALS['BE_USER']);

        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => $this->aiLabelTarget(),
        ]);
        $response = new TextResponse('hello');
        $next = new AiMiddlewareHandler(static fn () => $response);

        $result = $this->get(FlagAiContentMiddleware::class)->process($request, self::createStub(AiProviderInterface::class), $config, $next);

        self::assertSame($response, $result);
    }

    /**
     * Decoded rather than compared as a raw string: MySQL's native JSON
     * column type normalizes the stored value with spaces after colons/
     * commas on read-back, while sqlite returns it exactly as written
     * (compact, no spaces) - comparing the decoded structure is the only
     * assertion that holds across both.
     *
     * @return array<string, mixed>|null
     */
    private function fetchMetadata(): ?array
    {
        $raw = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->select(['tx_ailabel_metadata'], 'tt_content', ['uid' => $this->contentUid])->fetchOne();
        return is_string($raw) ? json_decode($raw, true) : null;
    }
}
