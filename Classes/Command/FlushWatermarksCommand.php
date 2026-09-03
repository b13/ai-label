<?php

declare(strict_types=1);

namespace B13\AiLabel\Command;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Configuration\ImageMarkerSettings;
use B13\AiLabel\Domain\Enum\ImageMarkerMode;
use B13\AiLabel\Imaging\ProcessedFileInvalidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Changing a *global* watermark default - imageMarker, watermarkPosition,
// watermarkColor - is the one case nothing else in this extension notices: those
// live in ExtensionConfiguration, so there is no DataHandler write and no FAL event
// to hang an invalidation off, and FAL keys processed files on (original file, task,
// configuration), none of which change either. The already-rendered variants
// therefore keep the old badge - or, after switching from "baked" to "overlay", keep
// a burned-in badge that the new mode then renders a second time on top.
#[AsCommand(
    name: 'ailabel:flushWatermarks',
    description: 'Flush the processed image variants of every AI-flagged file, so the "baked" watermark is re-rendered with the current global settings.',
)]
final class FlushWatermarksCommand extends Command
{
    public function __construct(
        private readonly ProcessedFileInvalidator $processedFileInvalidator,
        private readonly ImageMarkerSettings $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Deliberately not skipped in "off"/"overlay" mode: switching *away* from
        // "baked" is exactly when the stale variants still carry a burned-in badge.
        if ($this->settings->getMode() !== ImageMarkerMode::Baked) {
            $io->note(sprintf(
                'The image marker mode is currently "%s". Flushing anyway. Variants rendered while "baked" was active still carry a burned-in badge.',
                $this->settings->getMode()->value
            ));
        }

        $flushed = $this->processedFileInvalidator->invalidateForAllFlaggedFiles();

        if ($flushed === 0) {
            $io->success('No AI-flagged files found, nothing to flush.');
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Flushed the processed variants of %d AI-flagged file(s). They are regenerated on the next render.',
            $flushed
        ));

        return Command::SUCCESS;
    }
}
