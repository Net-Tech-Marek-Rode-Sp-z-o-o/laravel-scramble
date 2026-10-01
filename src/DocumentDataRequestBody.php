<?php

declare(strict_types=1);

namespace NetCode\Scramble;

use DateTimeInterface;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RouteInfo;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionNamedType;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Enums\DataTypeKind;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;
use UnitEnum;

/**
 * Documents the JSON request body of POST, PUT and PATCH endpoints whose input is
 * a spatie LaravelData object. The schema comes from the Data properties and their
 * validation attributes (Min, Max, Between, Size, Email, Uuid, Url). Rules returned
 * by a static `rules()` method are not read: they may depend on the payload, so
 * their limits are unknown at generation time. `In` is not read either; a closed
 * set belongs in a backed enum, which becomes the schema `enum`.
 *
 * A Scramble operation transformer; register it via
 * `Scramble::configure()->withOperationTransformers(...)`.
 */
final class DocumentDataRequestBody
{
    private const array BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    private const array FORMATS = [Email::class => 'email', Uuid::class => 'uuid', Url::class => 'uri'];

    public function __construct(
        private readonly DataConfig $dataConfig,
    ) {}

    public function __invoke(
        Operation $operation,
        RouteInfo $routeInfo,
    ): void {
        $dataClass = $this->bodyDataClass($routeInfo);

        if ($dataClass === null) {
            return;
        }

        $operation->addRequestBodyObject(
            RequestBodyObject::make()->setContent('application/json', Schema::fromType($this->objectFor($dataClass, []))),
        );
    }

    /** @return class-string<Data>|null */
    private function bodyDataClass(RouteInfo $routeInfo): string|null
    {
        if (! in_array(strtoupper($routeInfo->method), self::BODY_METHODS, true)) {
            return null;
        }

        foreach ($routeInfo->reflectionMethod()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Data::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * @param class-string<Data> $dataClass
     * @param list<string> $visited
     */
    private function objectFor(string $dataClass, array $visited): ObjectType
    {
        $object = new ObjectType;
        $required = [];

        foreach ($this->dataConfig->getDataClass($dataClass)->properties as $property) {
            if ($property->computed || $property->hidden) {
                continue;
            }

            $name = $property->inputMappedName ?? $property->name;
            $object->addProperty($name, $this->typeFor($property, [...$visited, $dataClass]));

            if (! $property->hasDefaultValue && ! $property->type->isNullable && ! $property->type->isOptional) {
                $required[] = $name;
            }
        }

        return $object->setRequired($required);
    }

    /** @param list<string> $visited */
    private function typeFor(DataProperty $property, array $visited): Type
    {
        $propertyType = $property->type;
        $type = match (true) {
            $propertyType->kind === DataTypeKind::DataObject => $this->nestedObject($propertyType->dataClass, $visited),
            $propertyType->kind->isDataCollectable() => (new ArrayType)->setItems($this->nestedObject($propertyType->dataClass, $visited)),
            $propertyType->kind !== DataTypeKind::Default => (new ArrayType)->setItems($this->scalarType($propertyType->iterableItemType)),
            default => $this->constrained($this->scalarType($this->firstAcceptedType($property)), $property),
        };

        return $propertyType->isNullable ? $type->nullable(true) : $type;
    }

    /** @param list<string> $visited */
    private function nestedObject(string|null $dataClass, array $visited): Type
    {
        $isKnownData = $dataClass !== null && is_subclass_of($dataClass, Data::class);

        return $isKnownData && ! in_array($dataClass, $visited, true)
            ? $this->objectFor($dataClass, $visited)
            : new ObjectType;
    }

    private function scalarType(string|null $typeName): Type
    {
        if ($typeName !== null && enum_exists($typeName)) {
            return $this->enumType($typeName);
        }

        if ($typeName !== null && is_a($typeName, DateTimeInterface::class, true)) {
            return (new StringType)->format('date-time');
        }

        return match ($typeName) {
            'int' => new IntegerType,
            'float' => new NumberType,
            'bool' => new BooleanType,
            default => new StringType,
        };
    }

    /** @param class-string<UnitEnum> $enum */
    private function enumType(string $enum): Type
    {
        $reflection = new ReflectionEnum($enum);
        $backing = $reflection->getBackingType();
        $type = $backing instanceof ReflectionNamedType && $backing->getName() === 'int' ? new IntegerType : new StringType;
        $values = array_map(
            fn (ReflectionEnumBackedCase $case): int|string => $case->getBackingValue(),
            array_filter($reflection->getCases(), fn (object $case): bool => $case instanceof ReflectionEnumBackedCase),
        );

        return $values === [] ? $type : $type->enum(array_values($values));
    }

    private function constrained(Type $type, DataProperty $property): Type
    {
        $attributes = $property->attributes;
        [$min, $max] = $this->bounds($property);

        if (($type instanceof StringType || $type instanceof NumberType) && $min !== null) {
            $type->setMin($min);
        }

        if (($type instanceof StringType || $type instanceof NumberType) && $max !== null) {
            $type->setMax($max);
        }

        foreach (self::FORMATS as $attribute => $format) {
            if ($attributes->has($attribute)) {
                $type->format($format);
            }
        }

        return $type;
    }

    /** @return array{int|float|null, int|float|null} */
    private function bounds(DataProperty $property): array
    {
        $attributes = $property->attributes;
        $size = $attributes->first(Size::class);
        $between = $attributes->first(Between::class);
        $min = $attributes->first(Min::class);
        $max = $attributes->first(Max::class);

        return match (true) {
            $size instanceof Size => [$this->literals($size->parameters())[0] ?? null, $this->literals($size->parameters())[0] ?? null],
            $between instanceof Between => [$this->literals($between->parameters())[0] ?? null, $this->literals($between->parameters())[1] ?? null],
            default => [
                $min instanceof Min ? $this->literals($min->parameters())[0] ?? null : null,
                $max instanceof Max ? $this->literals($max->parameters())[0] ?? null : null,
            ],
        };
    }

    /**
     * Keeps only literal values: an ExternalReference resolves at request time.
     *
     * @param array<array-key, mixed> $parameters
     * @return list<int|float>
     */
    private function literals(array $parameters): array
    {
        return array_values(array_filter($parameters, fn (mixed $value): bool => is_int($value) || is_float($value)));
    }

    private function firstAcceptedType(DataProperty $property): string|null
    {
        $accepted = array_key_first($property->type->getAcceptedTypes());

        return $accepted === null ? null : (string) $accepted;
    }
}
