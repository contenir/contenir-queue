<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Worker;

use Contenir\Queue\Worker\WorkerIdentity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gethostname;
use function getmypid;
use function preg_quote;
use function str_repeat;
use function substr;

#[Group('unit')]
final class WorkerIdentityTest extends TestCase
{
    #[Test]
    public function aLongHostIsCutSoTheLockFitsItsColumn(): void
    {
        $identity = WorkerIdentity::of(str_repeat('h', times: 39) . 'xy', 4_294_967_295, 'abcd1234');

        static::assertSame(
            [str_repeat('h', times: 39) . 'x:4294967295:abcd1234', str_repeat('h', times: 39) . 'xy'],
            [$identity->lock, $identity->host],
        );
    }

    #[Test]
    public function currentIsThisHostAndProcess(): void
    {
        $identity = WorkerIdentity::current();

        static::assertSame([gethostname(), getmypid()], [$identity->host, $identity->pid]);
    }

    #[Test]
    public function currentLockNamesTheHostProcessAndRun(): void
    {
        $lock = WorkerIdentity::current()->lock;

        static::assertMatchesRegularExpression(
            '/^'
                . preg_quote(substr((string) gethostname(), offset: 0, length: 40), delimiter: '/')
                . ':'
                . getmypid()
                . ':[0-9a-f]{8}$/D',
            $lock,
        );
    }

    #[Test]
    public function eachRunGetsItsOwnLock(): void
    {
        static::assertNotSame(WorkerIdentity::current()->lock, WorkerIdentity::current()->lock);
    }

    #[Test]
    public function ofBuildsTheLockFromHostProcessAndRun(): void
    {
        static::assertSame('web1:42:ab12', WorkerIdentity::of('web1', 42, 'ab12')->lock);
    }
}
