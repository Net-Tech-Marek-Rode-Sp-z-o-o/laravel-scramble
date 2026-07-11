<?php

declare(strict_types=1);

namespace NetCode\Scramble;

use Attribute;

/**
 * Overrides the OpenAPI tag (group) of a controller, taking precedence over any
 * namespace-derived tag. Segments are joined with " / " — e.g.
 * `#[ApiTag('Admin', 'Billing')]` yields "Admin / Billing".
 *
 * Read by the {@see TagByNamespace} transformer.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ApiTag
{
    public string $tag;

    public function __construct(string ...$segments)
    {
        $this->tag = implode(' / ', $segments);
    }
}
