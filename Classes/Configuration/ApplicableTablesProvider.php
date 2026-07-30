<?php

declare(strict_types=1);

namespace B13\AiLabel\Configuration;

use B13\AiLabel\Event\ApplicableTablesEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ApplicableTablesProvider
{
    private const DEFAULT_TABLES = [
        'tt_content',
        'pages',
        'sys_file_metadata',
    ];

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function getApplicableTables(): array
    {
        $applicableTablesEvent = new ApplicableTablesEvent(self::DEFAULT_TABLES);
        $this->eventDispatcher->dispatch($applicableTablesEvent);
        return $applicableTablesEvent->getApplicableTables();
    }
}
