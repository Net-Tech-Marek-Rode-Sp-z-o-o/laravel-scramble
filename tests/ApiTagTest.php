<?php

declare(strict_types=1);

namespace NetCode\Scramble\Tests;

use NetCode\Scramble\ApiTag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiTagTest extends TestCase
{
    #[Test]
    public function it_joins_segments_with_a_slash(): void
    {
        $this->assertSame('Admin / Billing', new ApiTag('Admin', 'Billing')->tag);
    }

    #[Test]
    public function it_accepts_a_single_segment(): void
    {
        $this->assertSame('Identity', new ApiTag('Identity')->tag);
    }
}
