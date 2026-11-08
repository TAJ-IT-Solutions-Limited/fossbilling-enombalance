<?php

declare(strict_types=1);

/**
 * Pure dashboard card for one GetBalance result.
 *
 * Copyright 2026 TAJHOST (https://tajhost.com)
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Enombalance;

final class BalanceCard
{
    public const CACHE_SECONDS = 900;

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function fromResult(array $result, string $locale, \DateTimeImmutable $updatedAt): array
    {
        $arabic = str_starts_with(strtolower($locale), 'ar');
        if (empty($result['ok'])) {
            return [
                'state' => 'error',
                'locale' => $arabic ? 'ar' : 'en',
                'dir' => $arabic ? 'rtl' : 'ltr',
                'title' => $arabic ? 'تعذر قراءة رصيد Enom' : 'Could not read Enom balance',
                'detail' => self::redactedError($result),
                'low' => false,
                'updated_at' => $updatedAt->format('H:i'),
            ];
        }

        $balance = self::money($result['balance'] ?? null);
        $available = array_key_exists('available_balance', $result)
            ? self::money($result['available_balance'])
            : null;

        return [
            'state' => 'ok',
            'locale' => $arabic ? 'ar' : 'en',
            'dir' => $arabic ? 'rtl' : 'ltr',
            'title' => $arabic
                ? 'رصيد Enom: ' . $balance . ' (تم التحديث ' . $updatedAt->format('H:i') . ')'
                : 'Enom balance: ' . $balance . ' (updated ' . $updatedAt->format('H:i') . ')',
            'available' => $available,
            'available_label' => $available === null
                ? null
                : ($arabic ? 'المتاح للشراء: ' . $available : 'Available for purchases: ' . $available),
            'low' => false,
            'low_label' => $arabic ? 'الرصيد منخفض' : 'Low balance',
            'updated_at' => $updatedAt->format('H:i'),
            'balance' => $balance,
        ];
    }

    /**
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    public static function applyThreshold(array $card, ?float $threshold): array
    {
        if ($card['state'] !== 'ok' || $threshold === null || $threshold <= 0) {
            return $card;
        }
        $amount = self::amount($card['balance'] ?? null);
        $card['low'] = $amount !== null && $amount < $threshold;
        if ($card['low']) {
            $card['state'] = 'low';
        }

        return $card;
    }

    public static function shouldAlert(?float $threshold, array $card, ?string $lastAlertDay, \DateTimeImmutable $now): bool
    {
        if ($threshold === null || $threshold <= 0 || ($card['state'] ?? '') !== 'low') {
            return false;
        }

        return $lastAlertDay !== $now->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function redactedError(array $result): string
    {
        $text = trim((string) ($result['error'] ?? ''));
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/(uid|pw|password|token)=[^&\s]+/i', '$1=[redacted]', $text) ?? $text;

        return mb_substr($text, 0, 180);
    }

    private static function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '$0.00';
        }
        if (!is_numeric($value)) {
            return '$0.00';
        }

        return '$' . number_format((float) $value, 2, '.', '');
    }

    private static function amount(mixed $value): ?float
    {
        if (!is_string($value)) {
            return null;
        }
        $number = ltrim($value, '$');
        if (!is_numeric($number)) {
            return null;
        }

        return (float) $number;
    }
}
