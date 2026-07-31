<?php

declare(strict_types=1);

namespace B13\AiLabel\Configuration;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

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

    public function isTableApplicable(string $table): bool
    {
        return in_array($table, $this->getApplicableTables(), true);
    }
}
