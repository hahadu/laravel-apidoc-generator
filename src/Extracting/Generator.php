<?php

namespace Hahadu\ApiDoc\Extracting;

use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Hahadu\ApiDoc\Tools\DocumentationConfig;
use Hahadu\ApiDoc\Tools\Utils;
use ReflectionClass;
use ReflectionMethod;

class Generator
{
    private DocumentationConfig $config;

    public function __construct(?DocumentationConfig $config = null)
    {
        $this->config = $config ?: new DocumentationConfig(config('apidoc'));
    }

    public function getUri(Route $route): string
    {
        return $route->uri();
    }

    public function getMethods(Route $route): array
    {
        return array_diff($route->methods(), ['HEAD']);
    }

    public function processRoute(Route $route, array $routeRules = []): array
    {
        [$controllerName, $methodName] = Utils::getRouteClassAndMethodNames($route->getAction());
        $controller = new ReflectionClass($controllerName);
        $method = $controller->getMethod($methodName);

        $parsedRoute = [
            'id' => md5($this->getUri($route) . ':' . implode($this->getMethods($route))),
            'methods' => $this->getMethods($route),
            'uri' => $this->getUri($route),
        ];
        $metadata = $this->fetchMetadata($controller, $method, $route, $routeRules, $parsedRoute);
        $parsedRoute['metadata'] = $metadata;

        $urlParameters = $this->fetchUrlParameters($controller, $method, $route, $routeRules, $parsedRoute);
        $parsedRoute['urlParameters'] = $urlParameters;
        $parsedRoute['cleanUrlParameters'] = $this->cleanParams($urlParameters);
        $parsedRoute['boundUri'] = Utils::getFullUrl($route, $parsedRoute['cleanUrlParameters']);

        $queryParameters = $this->fetchQueryParameters($controller, $method, $route, $routeRules, $parsedRoute);
        $parsedRoute['queryParameters'] = $queryParameters;
        $parsedRoute['cleanQueryParameters'] = $this->cleanParams($queryParameters);

        $headers = $this->fetchRequestHeaders($controller, $method, $route, $routeRules, $parsedRoute);
        $parsedRoute['headers'] = $headers;

        $bodyParameters = $this->fetchBodyParameters($controller, $method, $route, $routeRules, $parsedRoute);
        $parsedRoute['bodyParameters'] = $bodyParameters;
        $parsedRoute['cleanBodyParameters'] = $this->cleanParams($bodyParameters);

        $responses = $this->fetchResponses($controller, $method, $route, $routeRules, $parsedRoute);
        $parsedRoute['responses'] = $responses;
        $parsedRoute['showresponse'] = ! empty($responses);

        return $parsedRoute;
    }

    protected function fetchMetadata(ReflectionClass $controller, ReflectionMethod $method, Route $route, array $rulesToApply, array $context = []): array
    {
        $context['metadata'] = [
            'groupName' => $this->config->get('default_group', ''),
            'groupDescription' => '',
            'title' => '',
            'description' => '',
            'authenticated' => false,
        ];

        return $this->iterateThroughStrategies('metadata', $context, [$route, $controller, $method, $rulesToApply]);
    }

    protected function fetchUrlParameters(ReflectionClass $controller, ReflectionMethod $method, Route $route, array $rulesToApply, array $context = []): array
    {
        return $this->iterateThroughStrategies('urlParameters', $context, [$route, $controller, $method, $rulesToApply]);
    }

    protected function fetchQueryParameters(ReflectionClass $controller, ReflectionMethod $method, Route $route, array $rulesToApply, array $context = []): array
    {
        return $this->iterateThroughStrategies('queryParameters', $context, [$route, $controller, $method, $rulesToApply]);
    }

    protected function fetchBodyParameters(ReflectionClass $controller, ReflectionMethod $method, Route $route, array $rulesToApply, array $context = []): array
    {
        return $this->iterateThroughStrategies('bodyParameters', $context, [$route, $controller, $method, $rulesToApply]);
    }

    protected function fetchResponses(ReflectionClass $controller, ReflectionMethod $method, Route $route, array $rulesToApply, array $context = []): array
    {
        $responses = $this->iterateThroughStrategies('responses', $context, [$route, $controller, $method, $rulesToApply]);
        if (count($responses)) {
            return array_filter($responses, function ($response) {
                return $response['content'] != null;
            });
        }

        return [];
    }

    protected function fetchRequestHeaders(ReflectionClass $controller, ReflectionMethod $method, Route $route, array $rulesToApply, array $context = []): array
    {
        $headers = $this->iterateThroughStrategies('headers', $context, [$route, $controller, $method, $rulesToApply]);

        return array_filter($headers);
    }

    protected function iterateThroughStrategies(string $stage, array $context, array $arguments): array
    {
        $defaultStrategies = [
            'metadata' => [
                \Hahadu\ApiDoc\Extracting\Strategies\Metadata\GetFromDocBlocks::class,
            ],
            'urlParameters' => [
                \Hahadu\ApiDoc\Extracting\Strategies\UrlParameters\GetFromUrlParamTag::class,
            ],
            'queryParameters' => [
                \Hahadu\ApiDoc\Extracting\Strategies\QueryParameters\GetFromQueryParamTag::class,
            ],
            'headers' => [
                \Hahadu\ApiDoc\Extracting\Strategies\RequestHeaders\GetFromRouteRules::class,
            ],
            'bodyParameters' => [
                \Hahadu\ApiDoc\Extracting\Strategies\BodyParameters\GetFromBodyParamTag::class,
            ],
            'responses' => [
                \Hahadu\ApiDoc\Extracting\Strategies\Responses\UseTransformerTags::class,
                \Hahadu\ApiDoc\Extracting\Strategies\Responses\UseResponseTag::class,
                \Hahadu\ApiDoc\Extracting\Strategies\Responses\UseResponseFileTag::class,
                \Hahadu\ApiDoc\Extracting\Strategies\Responses\UseApiResourceTags::class,
                \Hahadu\ApiDoc\Extracting\Strategies\Responses\ResponseCalls::class,
            ],
        ];

        $strategies = $this->config->get("strategies.$stage", $defaultStrategies[$stage]);
        $context[$stage] = $context[$stage] ?? [];
        foreach ($strategies as $strategyClass) {
            $strategy = new $strategyClass($stage, $this->config);
            $strategyArgs = $arguments;
            $strategyArgs[] = $context;
            $results = $strategy(...$strategyArgs);
            if (! is_null($results)) {
                foreach ($results as $index => $item) {
                    if ($stage == 'responses') {
                        $context[$stage][] = $item;
                        continue;
                    }

                    if (! in_array($context[$stage], [null, ''], true) && in_array($item, [null, ''], true)) {
                        continue;
                    } else {
                        $context[$stage][$index] = $item;
                    }
                }
            }
        }

        return $context[$stage];
    }

    protected function cleanParams(array $params): array
    {
        $values = [];

        $params = array_filter($params, function ($details) {
            return ! is_null($details['value']);
        });

        foreach ($params as $paramName => $details) {
            $this->generateConcreteSampleForArrayKeys(
                $paramName,
                $details['value'],
                $values
            );
        }

        return $values;
    }

    protected function generateConcreteSampleForArrayKeys(string $paramName, mixed $paramExample, array &$values = []): void
    {
        if (Str::contains($paramName, '[')) {
            $paramName = str_replace(['][', '[', ']', '..'], ['.', '.', '', '.*.'], $paramName);
        }
        Arr::set($values, str_replace(['.*', '*.'], ['.0', '0.'], $paramName), $paramExample);
    }
}
