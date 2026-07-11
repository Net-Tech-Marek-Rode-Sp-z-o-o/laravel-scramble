<?php

declare(strict_types=1);

namespace NetCode\Scramble;

use Attribute;

/**
 * Declares the domain error HTTP statuses an endpoint may return (e.g. 404, 409),
 * so the OpenAPI generator can document them — these typically come from exceptions
 * mapped centrally (e.g. in bootstrap/app.php), which static analysis cannot trace.
 *
 * Read by the {@see DocumentErrorResponses} transformer.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ApiErrors
{
    /** @var list<int> */
    public array $statuses;

    public function __construct(
        int ...$statuses,
    ) {
        $this->statuses = array_values($statuses);
    }
}
