# net-code/laravel-scramble

[Scramble](https://scramble.dedoc.co) operation transformers for Laravel REST APIs —
the OpenAPI documentation glue extracted from the net-tech portfolio API.

## Install

```bash
composer require net-code/laravel-scramble
```

Pulls in `dedoc/scramble` and `spatie/laravel-data`.

## What's inside

| Class | Purpose |
|---|---|
| `NetCode\Scramble\TagByNamespace` | Tags each operation by its controller: an explicit `#[ApiTag]` wins, otherwise a resolver you supply computes the tag from the controller's namespace segments. |
| `NetCode\Scramble\DocumentErrorResponses` | Documents RFC 9457 problem+json errors from middleware, write methods, and `#[ApiErrors(...)]`. Scope with a namespace prefix. |
| `NetCode\Scramble\DocumentDataRequestBody` | Documents the JSON body of POST/PUT/PATCH endpoints whose input is a spatie `Data` object: properties, input names, required and nullable fields, enums, dates, nested Data, and the Min/Max/Between/Size/Email/Uuid/Url attributes. A static `rules()` method is not read. |
| `NetCode\Scramble\DocumentDataQueryParameters` | Documents GET query params for spatie `Data` request objects, which Scramble does not see natively. |
| `NetCode\Scramble\ApiTag` | Class attribute overriding an endpoint's tag (group), e.g. `#[ApiTag('Admin', 'Billing')]`. |
| `NetCode\Scramble\ApiErrors` | Class attribute declaring the domain error HTTP statuses an endpoint may return. |

## Usage

Register the transformers in a service provider's `boot()`. `TagByNamespace` is
convention-first (no annotation needed), with `#[ApiTag]` as a per-controller override.
The resolver receives the split namespace and returns the tag — here, audience-first
(`Admin / Billing`, `Client / Projects`), falling back to the context name when a
controller has no audience subfolder:

```php
use Dedoc\Scramble\Scramble;
use NetCode\Scramble\DocumentDataQueryParameters;
use NetCode\Scramble\DocumentDataRequestBody;
use NetCode\Scramble\DocumentErrorResponses;
use NetCode\Scramble\TagByNamespace;

Scramble::configure()->withOperationTransformers([
    new TagByNamespace('Contexts\\', static function (array $segments): ?string {
        $context = $segments[1] ?? null;

        if ($context === null) {
            return null;
        }

        $at = array_search('Controllers', $segments, true);
        $audience = is_int($at) && isset($segments[$at + 2]) ? $segments[$at + 1] : null;

        return $audience === null ? $context : $audience.' / '.$context;
    }),
    new DocumentErrorResponses(namespacePrefix: 'Contexts\\'),
    $this->app->make(DocumentDataQueryParameters::class),
    $this->app->make(DocumentDataRequestBody::class),
]);
```

Any controller can opt out of the convention: `#[ApiTag('Reports')]` forces its group,
and `#[ApiErrors(404, 409)]` adds domain error responses.

## License

MIT
