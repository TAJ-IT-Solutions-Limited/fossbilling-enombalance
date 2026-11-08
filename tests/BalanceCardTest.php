<?php

declare(strict_types=1);

namespace Box\Mod\Enombalance;

use PHPUnit\Framework\TestCase;

final class BalanceCardTest extends TestCase
{
    public function testOkCardUsesTheBalanceAndDoesNotRepeatTheCall(): void
    {
        $now = new \DateTimeImmutable('2026-11-08 09:15:00');
        $card = BalanceCard::fromResult([
            'ok' => true,
            'command' => 'GetBalance',
            'balance' => '42.5',
        ], 'en', $now);

        self::assertSame('ok', $card['state']);
        self::assertSame('Enom balance: $42.50 (updated 09:15)', $card['title']);
        self::assertNull($card['available_label']);
    }

    public function testAvailableBalanceGetsItsOwnLineInArabic(): void
    {
        $card = BalanceCard::fromResult([
            'ok' => true,
            'balance' => '10',
            'available_balance' => '8.25',
        ], 'ar', new \DateTimeImmutable('2026-11-08 09:15:00'));

        self::assertSame('ar', $card['locale']);
        self::assertSame('rtl', $card['dir']);
        self::assertSame('المتاح للشراء: $8.25', $card['available_label']);
        self::assertStringContainsString('رصيد Enom', $card['title']);
    }

    public function testMissingResultIsAnErrorWithoutAToken(): void
    {
        $card = BalanceCard::fromResult([
            'ok' => false,
            'error' => 'uid=secret&pw=secret response had no ErrCount',
        ], 'en', new \DateTimeImmutable('2026-11-08 09:15:00'));

        self::assertSame('error', $card['state']);
        self::assertSame('Could not read Enom balance', $card['title']);
        self::assertStringNotContainsString('secret', $card['detail']);
        self::assertStringContainsString('[redacted]', $card['detail']);
    }

    public function testThresholdMarksLowAndAlertsOncePerDay(): void
    {
        $now = new \DateTimeImmutable('2026-11-08 09:15:00');
        $card = BalanceCard::applyThreshold(
            BalanceCard::fromResult(['ok' => true, 'balance' => '3'], 'en', $now),
            10.0,
        );

        self::assertSame('low', $card['state']);
        self::assertTrue(BalanceCard::shouldAlert(10.0, $card, null, $now));
        self::assertFalse(BalanceCard::shouldAlert(10.0, $card, '2026-11-08', $now));
        self::assertFalse(BalanceCard::shouldAlert(null, $card, null, $now));
    }

    public function testCacheMissLoadsOnceAndTheNextReadHits(): void
    {
        $cache = new class {
            public array $data = [];
            public int $loads = 0;

            public function get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function set(string $key, mixed $value, int $ttl): void
            {
                $this->data[$key] = $value;
            }
        };
        $store = new BalanceCache($cache);
        [$first, $hit] = $store->remember('enombalance.card', BalanceCard::CACHE_SECONDS, function () use ($cache): array {
            $cache->loads++;

            return ['state' => 'ok'];
        });
        [$second, $again] = $store->remember('enombalance.card', BalanceCard::CACHE_SECONDS, function () use ($cache): array {
            $cache->loads++;

            return ['state' => 'fresh'];
        });

        self::assertFalse($hit);
        self::assertTrue($again);
        self::assertSame($first, $second);
        self::assertSame(1, $cache->loads);
        self::assertSame(900, BalanceCard::CACHE_SECONDS);
    }

    public function testSourceCallsTestApiAndNothingElse(): void
    {
        $adapter = new class {
            public array $commands = [];

            public function testApi(): array
            {
                $this->commands[] = 'GetBalance';

                return ['ok' => true, 'command' => 'GetBalance', 'balance' => '1', 'uid' => 'nope', 'pw' => 'nope'];
            }

            public function purchase(): array
            {
                $this->commands[] = 'Purchase';

                return [];
            }
        };
        $result = (new EnomBalanceSource())->fetch($adapter);

        self::assertSame(['GetBalance'], $adapter->commands);
        self::assertArrayNotHasKey('uid', $result);
        self::assertArrayNotHasKey('pw', $result);
        $source = file_get_contents(dirname(__DIR__) . '/src/EnomBalanceSource.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('Purchase', $source);
        self::assertStringNotContainsString('_makeRequest', $source);
    }

    public function testSettingsAndRefreshRequireManage(): void
    {
        $api = file_get_contents(dirname(__DIR__) . '/src/Api/Admin.php');
        $service = file_get_contents(dirname(__DIR__) . '/src/Service.php');
        self::assertIsString($api);
        self::assertIsString($service);
        self::assertSame(3, substr_count($api, "checkPermissions('enombalance', 'manage')"));
        self::assertStringContainsString("'manage' => []", $service);
        self::assertStringContainsString("'manage_settings' => []", $service);
    }
}
