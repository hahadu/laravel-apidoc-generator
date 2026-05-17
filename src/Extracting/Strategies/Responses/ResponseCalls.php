<?php

namespace Hahadu\ApiDoc\Extracting\Strategies\Responses;

use Dingo\Api\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Hahadu\ApiDoc\Extracting\ParamHelpers;
use Hahadu\ApiDoc\Extracting\Strategies\Strategy;
use Hahadu\ApiDoc\Tools\Flags;
use Hahadu\ApiDoc\Tools\Utils;
use ReflectionClass;
use ReflectionMethod;

/**
 * Make a call to the route and retrieve its response.
 */
class ResponseCalls extends Strategy
{
    use ParamHelpers;

    public function __invoke(Route $route, ReflectionClass $controller, ReflectionMethod $method, array $routeRules, array $context = []): ?array
    {
        $rulesToApply = $routeRules['response_calls'] ?? [];
        if (! $this->shouldMakeApiCall($route, $rulesToApply, $context)) {
            return null;
        }

        $this->configureEnvironment($rulesToApply);

        $bodyParameters = array_merge($context['cleanBodyParameters'], $rulesToApply['bodyParams'] ?? []);
        $queryParameters = array_merge($context['cleanQueryParameters'], $rulesToApply['queryParams'] ?? []);
        $urlParameters = $context['cleanUrlParameters'];
        $request = $this->prepareRequest($route, $rulesToApply, $urlParameters, $bodyParameters, $queryParameters, $context['headers'] ?? []);

        try {
            $response = $this->makeApiCall($request);
            $response = [
                [
                    'status' => $response->getStatusCode(),
                    'content' => $response->getContent(),
                ],
            ];
        } catch (\Exception $e) {
            echo 'Exception thrown during response call for [' . implode(',', $route->methods) . "] {$route->uri}.\n";
            if (Flags::$shouldBeVerbose) {
                Utils::dumpException($e);
            } else {
                echo "Run this again with the --verbose flag to see the exception.\n";
            }
            $response = null;
        } finally {
            $this->finish();
        }

        return $response;
    }

    private function configureEnvironment(array $rulesToApply): void
    {
        $this->startDbTransaction();
        $this->setEnvironmentVariables($rulesToApply['env'] ?? []);
        $this->setLaravelConfigs($rulesToApply['config'] ?? []);
    }

    protected function prepareRequest(Route $route, array $rulesToApply, array $urlParams, array $bodyParams, array $queryParams, array $headers): Request
    {
        $uri = Utils::getFullUrl($route, $urlParams);
        $routeMethods = $this->getMethods($route);
        $method = array_shift($routeMethods);
        $cookies = $rulesToApply['cookies'] ?? [];

        $request = Request::create($uri, $method, [], $cookies, [], $this->transformHeadersToServerVars($headers), json_encode($bodyParams));
        $request = $this->addHeaders($request, $route, $headers);

        $request = $this->addQueryParameters($request, $queryParams);
        $request = $this->addBodyParameters($request, $bodyParams);

        return $request;
    }

    /** @deprecated Not guaranteed to overwrite application's env. Use Laravel config variables instead. */
    private function setEnvironmentVariables(array $env): void
    {
        foreach ($env as $name => $value) {
            putenv("$name=$value");

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private function setLaravelConfigs(array $config): void
    {
        if (empty($config)) {
            return;
        }

        foreach ($config as $name => $value) {
            config([$name => $value]);
        }
    }

    private function startDbTransaction(): void
    {
        try {
            app('db')->beginTransaction();
        } catch (\Exception $e) {
        }
    }

    private function endDbTransaction(): void
    {
        try {
            app('db')->rollBack();
        } catch (\Exception $e) {
        }
    }

    private function finish(): void
    {
        $this->endDbTransaction();
    }

    public function callDingoRoute(Request $request): Response
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = app(Dispatcher::class);

        foreach ($request->headers as $header => $value) {
            $dispatcher->header($header, $value);
        }

        $dispatcher->on($request->header('SERVER_NAME'))
            ->with($request->request->all());

        $uri = $request->getRequestUri();
        $query = $request->getQueryString();
        if (! empty($query)) {
            $uri .= "?$query";
        }
        $response = call_user_func_array([$dispatcher, strtolower($request->method())], [$uri]);

        if (! $response instanceof Response) {
            $response = response()->json($response);
        }

        return $response;
    }

    public function getMethods(Route $route): array
    {
        return array_diff($route->methods(), ['HEAD']);
    }

    private function addHeaders(Request $request, Route $route, ?array $headers): Request
    {
        if ($route->getDomain()) {
            $request->headers->add([
                'HOST' => $route->getDomain(),
            ]);
            $request->server->add([
                'HTTP_HOST' => $route->getDomain(),
                'SERVER_NAME' => $route->getDomain(),
            ]);
        }

        $headers = collect($headers);

        if (($headers->get('Accept') ?: $headers->get('accept')) === 'application/json') {
            $request->setRequestFormat('json');
        }

        return $request;
    }

    private function addQueryParameters(Request $request, array $query): Request
    {
        $request->query->add($query);
        $request->server->add(['QUERY_STRING' => http_build_query($query)]);

        return $request;
    }

    private function addBodyParameters(Request $request, array $body): Request
    {
        $request->request->add($body);

        return $request;
    }

    protected function makeApiCall(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        if (config('apidoc.router') == 'dingo') {
            return $this->callDingoRoute($request);
        }

        return $this->callLaravelRoute($request);
    }

    protected function callLaravelRoute(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        if (app()->bound(\Illuminate\Contracts\Http\Kernel::class)) {
            $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
        } else {
            $kernel = app();
            $response = $kernel->handle($request);
        }

        return $response;
    }

    protected function shouldMakeApiCall(Route $route, array $rulesToApply, array $context): bool
    {
        $allowedMethods = $rulesToApply['methods'] ?? [];
        if (empty($allowedMethods)) {
            return false;
        }

        $successResponses = collect($context['responses'])->filter(function ($response) {
            return ((string) $response['status'])[0] == '2';
        })->count();
        if ($successResponses) {
            return false;
        }

        if (is_string($allowedMethods) && $allowedMethods == '*') {
            return true;
        }

        if (array_search('*', $allowedMethods) !== false) {
            return true;
        }

        $routeMethods = $this->getMethods($route);
        if (in_array(array_shift($routeMethods), $allowedMethods)) {
            return true;
        }

        return false;
    }

    protected function transformHeadersToServerVars(array $headers): array
    {
        $server = [];
        $prefix = 'HTTP_';
        foreach ($headers as $name => $value) {
            $name = strtr(strtoupper($name), '-', '_');
            if (! Str::startsWith($name, $prefix) && $name !== 'CONTENT_TYPE') {
                $name = $prefix . $name;
            }
            $server[$name] = $value;
        }

        return $server;
    }
}
