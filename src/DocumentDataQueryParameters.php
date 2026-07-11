<?php

declare(strict_types=1);

namespace NetCode\Scramble;

use BackedEnum;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RouteInfo;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;

/**
 * Documents the query parameters of GET endpoints whose input is a spatie
 * LaravelData object. Scramble ships no bridge for `Data` request objects, so a
 * `ListUsersData $data` controller argument is invisible to its parameter
 * extractors — filters, sorting and pagination go undocumented. This introspects
 * that Data argument via spatie's own DataConfig and emits one query parameter
 * per property, honouring its resolved input name, nullability and default.
 *
 * A Scramble operation transformer; register it via
 * `Scramble::configure()->withOperationTransformers(...)`.
 */
final class DocumentDataQueryParameters
{
    public function __construct(
        private readonly DataConfig $dataConfig,
    ) {}

    public function __invoke(
        Operation $operation,
        RouteInfo $routeInfo,
    ): void {
        if (strtoupper($routeInfo->method) !== 'GET') {
            return;
        }

        $method = $routeInfo->reflectionMethod();

        if ($method === null) {
            return;
        }

        foreach ($method->getParameters() as $parameter) {
            $dataClass = $this->dataClass($parameter);

            if ($dataClass === null) {
                continue;
            }

            $operation->addParameters($this->parametersFor($dataClass));
        }
    }

    /** @return class-string<Data>|null */
    private function dataClass(ReflectionParameter $parameter): string|null
    {
        $name = $this->namedType($parameter->getType());

        return $name !== null && is_subclass_of($name, Data::class) ? $name : null;
    }

    /**
     * @param class-string<Data> $dataClass
     * @return list<Parameter>
     */
    private function parametersFor(string $dataClass): array
    {
        $parameters = [];

        foreach ($this->dataConfig->getDataClass($dataClass)->properties as $property) {
            if ($property->computed) {
                continue;
            }

            $parameter = Parameter::make($property->inputMappedName ?? $property->name, 'query');
            $parameter->required = ! $property->hasDefaultValue && ! $property->type->isNullable;
            $parameter->setSchema(Schema::fromType($this->schemaType($property)));

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    private function schemaType(DataProperty $property): Type
    {
        $acceptedType = array_key_first($property->type->getAcceptedTypes());
        $typeName = $acceptedType === null ? null : (string) $acceptedType;
        $type = $this->baseType($typeName);

        if ($typeName !== null && enum_exists($typeName)) {
            $values = [];

            foreach (new ReflectionEnum($typeName)->getCases() as $case) {
                if ($case instanceof ReflectionEnumBackedCase) {
                    $values[] = $case->getBackingValue();
                }
            }

            if ($values !== []) {
                $type->enum($values);
            }
        }

        if ($property->hasDefaultValue) {
            $default = $property->defaultValue;

            if ($default instanceof BackedEnum) {
                $type->default($default->value);
            } elseif (is_scalar($default)) {
                $type->default($default);
            }
        }

        return $type;
    }

    private function baseType(string|null $typeName): Type
    {
        if ($typeName !== null && enum_exists($typeName)) {
            $backing = new ReflectionEnum($typeName)->getBackingType();

            return $backing instanceof ReflectionNamedType && $backing->getName() === 'int'
                ? new IntegerType
                : new StringType;
        }

        return match ($typeName) {
            'int' => new IntegerType,
            'float' => new NumberType,
            'bool' => new BooleanType,
            default => new StringType,
        };
    }

    private function namedType(ReflectionType|null $type): string|null
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? null : $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType && ! $member->isBuiltin()) {
                    return $member->getName();
                }
            }
        }

        return null;
    }
}
