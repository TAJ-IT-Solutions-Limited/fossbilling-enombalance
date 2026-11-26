<?php

declare(strict_types=1);

namespace Box\Mod\Enombalance;

use PHPUnit\Framework\TestCase;

final class RedactTest extends TestCase
{
    public function testNestedSecretsAreRemovedAndOnlyBalanceFieldsRemain(): void
    {
        $out = EnomBalanceSource::allow(EnomBalanceSource::redact([
            'ok' => true,
            'balance' => '10',
            'available_balance' => '8',
            'uid' => 'reseller',
            'pw' => 'secret-pw',
            'api_key' => 'k',
            'auth' => 'a',
            'nested' => ['password' => 'nested-pass', 'token' => 't'],
            'error' => 'uid=reseller&pw=secret-pw',
        ]));

        self::assertSame([
            'ok' => true,
            'balance' => '10',
            'available_balance' => '8',
            'error' => 'uid=[redacted]&pw=[redacted]',
        ], $out);
        self::assertStringNotContainsString('secret-pw', json_encode($out));
        self::assertStringNotContainsString('nested-pass', json_encode($out));
    }

    public function testEachStaffMemberHasASeparateRefreshLock(): void
    {
        self::assertSame('enombalance.refresh.4', RefreshLock::key(4));
        self::assertSame('enombalance.refresh.9', RefreshLock::key(9));
        self::assertNotSame(RefreshLock::key(4), RefreshLock::key(9));
        $card = RefreshLock::inProgress(['state' => 'ok', 'balance' => '$1.00']);
        self::assertTrue($card['refresh_in_progress']);
        self::assertSame('Refresh in progress', $card['refresh_message']);
        self::assertSame('$1.00', $card['balance']);
    }
}
