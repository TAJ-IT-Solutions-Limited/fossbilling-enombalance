<?php

declare(strict_types=1);

namespace FOSSBilling {
    interface InjectionAwareInterface
    {
        public function setDi(\Pimple\Container $di): void;

        public function getDi(): ?\Pimple\Container;
    }
}

namespace FOSSBilling\Interfaces {
    interface WidgetProviderInterface
    {
        public function getWidgets(): array;
    }
}

namespace Pimple {
    class Container implements \ArrayAccess
    {
        public array $values = [];

        public function offsetExists(mixed $offset): bool
        {
            return array_key_exists($offset, $this->values);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->values[$offset];
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->values[$offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->values[$offset]);
        }
    }
}

namespace {
require dirname(__DIR__) . '/src/Service.php';
require dirname(__DIR__) . '/src/EnomBalanceSource.php';
require dirname(__DIR__) . '/src/BalanceCache.php';
require dirname(__DIR__) . '/src/BalanceCard.php';

use Box\Mod\Enombalance\Service;

$failed = 0;

function check(bool $ok, string $message): void
{
    global $failed;
    if ($ok) {
        fwrite(STDOUT, "ok $message\n");

        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL $message\n");
}

final class CronCache
{
    public array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->data[$key] = $value;
    }
}

final class CronMail
{
    public array $sent = [];

    public function sendMail(string $to, string $from, string $subject, string $body): void
    {
        $this->sent[] = $to;
    }
}

final class CronAdapter
{
    public int $calls = 0;

    public function __construct(private array $result)
    {
    }

    public function testApi(): array
    {
        $this->calls++;

        return $this->result;
    }
}

function cronService(string $threshold, array $result): array
{
    $cache = new CronCache();
    $mail = new CronMail();
    $adapter = new CronAdapter($result);
    $di = new \Pimple\Container();
    $di['cache'] = $cache;
    $di['logger'] = new class {
        public function warning(string $message): void
        {
        }
    };
    $di['db'] = new class {
        public function findOne(string $model, string $where, array $params): object
        {
            if ($model === 'Admin') {
                return (object) ['email' => 'cron@example.com'];
            }

            return (object) ['id' => 1];
        }
    };
    $di['mod_config'] = static fn (): array => ['low_balance_threshold' => $threshold];
    $di['mod_service'] = static function (string $name) use ($mail, $adapter): object {
        return match ($name) {
            'system' => new class {
                public function getParamValue(string $key): string
                {
                    return 'accounts@example.com';
                }
            },
            'email' => $mail,
            'servicedomain' => new class($adapter) {
                public function __construct(private CronAdapter $adapter)
                {
                }

                public function registrarGetRegistrarAdapter(object $registrar): CronAdapter
                {
                    return $this->adapter;
                }
            },
            default => new class {
            },
        };
    };
    $service = new Service();
    $service->setDi($di);

    return [$service, $mail, $adapter, $cache];
}

[$low, $lowMail, $lowAdapter, $lowCache] = cronService('10', ['ok' => true, 'balance' => '3']);
$low->checkBalanceFromCron();
check(count($lowMail->sent) === 1, 'a low balance from cron sends one email');
check($lowAdapter->calls === 1, 'cron reads the balance once');
$low->checkBalanceFromCron();
check(count($lowMail->sent) === 1, 'a second cron run inside the hour does not send again');
check($lowAdapter->calls === 1, 'a second cron run inside the hour does not read Enom again');
unset($lowCache->data['enombalance.cron'], $lowCache->data['enombalance.card']);
$low->checkBalanceFromCron();
check(count($lowMail->sent) === 1, 'the same day does not send a second email');

[$none, $noneMail, $noneAdapter] = cronService('', ['ok' => true, 'balance' => '0']);
$none->checkBalanceFromCron();
check($noneMail->sent === [], 'no threshold sends no email');

[$missing, $missingMail] = cronService('10', ['ok' => true]);
$missing->checkBalanceFromCron();
check($missingMail->sent === [], 'an unreadable balance sends no email');

[$failedRead, $failedMail] = cronService('10', ['ok' => false, 'error' => 'Could not read Enom balance']);
$failedRead->checkBalanceFromCron();
check($failedMail->sent === [], 'a failed balance read sends no email');

if ($failed > 0) {
    exit(1);
}
fwrite(STDOUT, "all passed\n");
}
