<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Job;

use Contenir\Queue\Tests\TestAsset\Job\JobFactory;
use JsonException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class JobTest extends TestCase
{
    #[Test]
    public function decodesAListPayload(): void
    {
        static::assertSame([1, 2], JobFactory::running(payload: '[1,2]')->payload());
    }

    #[Test]
    public function decodesThePayload(): void
    {
        static::assertSame(
            ['id' => 7, 'tags' => ['a']],
            JobFactory::running(payload: '{"id":7,"tags":["a"]}')->payload(),
        );
    }

    #[Test]
    public function rejectsAPayloadThatIsNotAnObjectOrArray(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('The job payload is not a JSON object or array');

        JobFactory::running(payload: '"text"')->payload();
    }

    #[Test]
    public function rejectsAPayloadThatIsNotJson(): void
    {
        $this->expectException(JsonException::class);

        JobFactory::running(payload: '{oops')->payload();
    }
}
