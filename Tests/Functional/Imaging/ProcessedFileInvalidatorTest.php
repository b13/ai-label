<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Imaging;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Event\BeforeProcessedFileInvalidatedEvent;
use B13\AiLabel\Imaging\ProcessedFileInvalidator;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ProcessedFileInvalidatorTest extends FunctionalTestCase
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

    /**
     * Constructed by hand (real core services fetched via $this->get(), a recording
     * spy for the one dependency under test) rather than $this->get(ProcessedFileInvalidator::class):
     * swapping EventDispatcherInterface in the compiled DI container for one test
     * method isn't practical, and this class has no other reason to need the container.
     *
     * @param list<object> $dispatched
     */
    private function buildInvalidator(array &$dispatched): ProcessedFileInvalidator
    {
        $spy = new class($dispatched) implements EventDispatcherInterface {
            /** @param list<object> $dispatched */
            public function __construct(private array &$dispatched)
            {
            }

            public function dispatch(object $event): object
            {
                $this->dispatched[] = $event;
                return $event;
            }
        };

        return new ProcessedFileInvalidator(
            new NullLogger(),
            $this->get(ProcessedFileRepository::class),
            $this->get(ResourceFactory::class),
            $this->get(ConnectionPool::class),
            $spy,
        );
    }

    #[Test]
    public function invalidatingAnExistingProcessedFileDispatchesOneEvent(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/FlaggedFileWithProcessedVariant.csv');
        mkdir(Environment::getPublicPath() . '/fileadmin/_processed_', 0777, true);
        copy(__DIR__ . '/Fixtures/flagged.jpg', Environment::getPublicPath() . '/fileadmin/_processed_/processed.jpg');

        $dispatched = [];
        $this->buildInvalidator($dispatched)->invalidateForFileMetadata(1);

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(BeforeProcessedFileInvalidatedEvent::class, $dispatched[0]);

        $event = $dispatched[0];
        self::assertSame('fileadmin/_processed_/processed.jpg', $event->processedFilePublicUrl);
    }

    #[Test]
    public function invalidatingAFileWithNoProcessedVariantsDispatchesNothing(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Images.csv');

        $dispatched = [];
        $this->buildInvalidator($dispatched)->invalidateForFileMetadata(1);

        self::assertSame([], $dispatched);
    }
}
