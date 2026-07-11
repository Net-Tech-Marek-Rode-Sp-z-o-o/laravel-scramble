<?php

declare(strict_types=1);

namespace NetCode\Scramble;

use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Documents the RFC 9457 problem+json error responses an endpoint can return.
 * Scramble cannot see a global exception render map nor handlers resolved
 * dynamically (e.g. via a command bus), so we derive them here: 401/403/429 from
 * middleware, 422 for write methods, plus domain statuses declared per controller
 * via #[ApiErrors(...)].
 *
 * A Scramble operation transformer; register it via
 * `Scramble::configure()->withOperationTransformers(...)`.
 */
final class DocumentErrorResponses
{
    /** @var list<string> */
    private const array WRITE_METHODS = ['POST', 'PUT', 'PATCH'];

    /** @param string $namespacePrefix only document controllers under this prefix (empty = all) */
    public function __construct(
        private readonly string $namespacePrefix = '',
    ) {}

    public function __invoke(
        Operation $operation,
        RouteInfo $routeInfo,
    ): void {
        $class = $routeInfo->className();

        if ($class === null || ! str_starts_with($class, $this->namespacePrefix)) {
            return;
        }

        $statuses = [];

        foreach ($routeInfo->route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'auth')) {
                $statuses[] = HttpResponse::HTTP_UNAUTHORIZED;
            }

            if (str_starts_with($middleware, 'abilities') || str_starts_with($middleware, 'ability')) {
                $statuses[] = HttpResponse::HTTP_FORBIDDEN;
            }

            if (str_starts_with($middleware, 'throttle')) {
                $statuses[] = HttpResponse::HTTP_TOO_MANY_REQUESTS;
            }
        }

        if (in_array(strtoupper($routeInfo->method), self::WRITE_METHODS, true)) {
            $statuses[] = HttpResponse::HTTP_UNPROCESSABLE_ENTITY;
        }

        foreach ($this->declaredErrors($class) as $status) {
            $statuses[] = $status;
        }

        foreach (array_unique($statuses) as $status) {
            if (! $this->alreadyDocumented($operation, $status)) {
                $operation->addResponse($this->problemResponse($status));
            }
        }
    }

    /** @return list<int> */
    private function declaredErrors(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $attributes = new ReflectionClass($class)->getAttributes(ApiErrors::class);

        return $attributes === [] ? [] : $attributes[0]->newInstance()->statuses;
    }

    private function alreadyDocumented(Operation $operation, int $status): bool
    {
        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && (int) $response->code === $status) {
                return true;
            }
        }

        return false;
    }

    private function problemResponse(int $status): Response
    {
        $body = new ObjectType;
        $body->addProperty('type', new StringType);
        $body->addProperty('title', new StringType);
        $body->addProperty('status', new IntegerType);
        $body->addProperty('detail', new StringType);

        if ($status === HttpResponse::HTTP_UNPROCESSABLE_ENTITY) {
            $body->addProperty('errors', new ObjectType);
            $body->addProperty('code', new StringType);
        }

        $body->setRequired(['type', 'title', 'status']);

        return Response::make($status)
            ->setDescription(HttpResponse::$statusTexts[$status] ?? 'Error')
            ->setContent('application/problem+json', Schema::fromType($body));
    }
}
