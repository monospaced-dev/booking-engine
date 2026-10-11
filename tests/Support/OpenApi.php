<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use PHPUnit\Framework\Assert;

class OpenApi
{
    public static function validator(): ResponseValidator
    {
        static $validator;

        return $validator ??= (new ValidatorBuilder)
            ->fromYamlFile(base_path('docs/openapi.yaml'))->getResponseValidator();
    }

    public static function assertResponse(TestResponse $response, string $path, string $method): void
    {
        self::validator()->validate(new OperationAddress($path, strtolower($method)), new Response(
            $response->getStatusCode(), $response->headers->all(), $response->getContent(),
        ));
        // The library checks headers and JSON schema; explicitly check bodyless responses.
        if ($response->getStatusCode() === 204) {
            Assert::assertSame('', $response->getContent());
        }
        Assert::assertTrue(true, 'Response matches the OpenAPI operation and status.');
    }
}
