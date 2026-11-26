<?php

declare(strict_types=1);

/**
 * Copyright 2026 TAJHOST (https://tajhost.com)
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Enombalance;

final class RefreshLock
{
    public static function key(?int $staffId): string
    {
        return $staffId === null || $staffId < 1 ? 'enombalance.refresh' : 'enombalance.refresh.' . $staffId;
    }

    /**
     * @param array<string, mixed>|null $cached
     * @return array<string, mixed>
     */
    public static function inProgress(?array $cached): array
    {
        $card = $cached ?? [
            'state' => 'error',
            'title' => 'Could not read Enom balance',
        ];
        $card['refresh_in_progress'] = true;
        $card['refresh_message'] = 'Refresh in progress';

        return $card;
    }
}
