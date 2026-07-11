<?php

declare(strict_types=1);

namespace NetCode\Scramble\Tests;

use NetCode\Scramble\ApiErrors;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiErrorsTest extends TestCase
{
    #[Test]
    public function it_collects_the_declared_statuses_as_a_list(): void
    {
        $errors = new ApiErrors(404, 409, 422);

        $this->assertSame([404, 409, 422], $errors->statuses);
    }

    #[Test]
    public function it_defaults_to_no_statuses(): void
    {
        $this->assertSame([], new ApiErrors()->statuses);
    }
}
