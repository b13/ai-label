<?php

declare(strict_types=1);

namespace B13\AiLabel\Domain\Model;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Enum\WatermarkColor;
use B13\AiLabel\Domain\Enum\WatermarkPosition;

// Immutable value object for the tx_ailabel_watermark JSON column on
// sys_file_metadata - a per-file override of the global watermarkPosition/
// watermarkColor ext_conf defaults. Not folded into AiMetadata: that object's
// shape (origin + review workflow) is unrelated. Null properties mean "inherit
// the global default", resolved by AiWatermark.
final class WatermarkOverride
{
    private ?WatermarkPosition $position = null;
    private ?WatermarkColor $color = null;

    public static function fromJsonString(?string $json): self
    {
        if ($json === null || $json === '') {
            return new self();
        }
        $decoded = json_decode($json, true);
        return self::fromArray(is_array($decoded) ? $decoded : null);
    }

    public static function fromArray(?array $data): self
    {
        $override = new self();
        if ($data === null) {
            return $override;
        }

        $override->position = WatermarkPosition::tryFrom((string)($data['position'] ?? ''));
        $override->color = WatermarkColor::tryFrom((string)($data['color'] ?? ''));
        return $override;
    }

    public function getPosition(): ?WatermarkPosition
    {
        return $this->position;
    }

    public function getColor(): ?WatermarkColor
    {
        return $this->color;
    }

    public function withPosition(?WatermarkPosition $position): self
    {
        $clone = clone $this;
        $clone->position = $position;
        return $clone;
    }

    public function withColor(?WatermarkColor $color): self
    {
        $clone = clone $this;
        $clone->color = $color;
        return $clone;
    }

    /**
     * The value to assign to DataHandler's $fieldArray['tx_ailabel_watermark'] - a
     * plain array, DataHandler/Doctrine encode it themselves for the json column.
     */
    public function toArray(): array
    {
        return [
            'position' => $this->position?->value,
            'color' => $this->color?->value,
        ];
    }
}
