<?php

declare(strict_types=1);

namespace NetCode\Scramble;

use Closure;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use ReflectionClass;

/**
 * Tags each operation by its controller. An explicit `#[ApiTag]` on the controller
 * class wins; otherwise the tag is computed from the controller's namespace by the
 * given resolver, which receives the fully-split namespace segments and returns a
 * tag string (or null to leave the operation untagged).
 *
 * A Scramble operation transformer; register it via
 * `Scramble::configure()->withOperationTransformers(...)`.
 */
final class TagByNamespace
{
    /** @param Closure(list<string>): (string|null) $resolver */
    public function __construct(
        private readonly string $prefix,
        private readonly Closure $resolver,
    ) {}

    public function __invoke(
        Operation $operation,
        RouteInfo $routeInfo,
    ): void {
        $class = $routeInfo->className();

        if ($class === null || ! str_starts_with($class, $this->prefix)) {
            return;
        }

        $tag = $this->declaredTag($class) ?? ($this->resolver)(explode('\\', $class));

        if ($tag !== null && $tag !== '') {
            $operation->setTags([$tag]);
        }
    }

    private function declaredTag(string $class): string|null
    {
        if (! class_exists($class)) {
            return null;
        }

        $attributes = new ReflectionClass($class)->getAttributes(ApiTag::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->tag;
    }
}
