<?php

namespace Hahadu\ApiDoc\Writing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use Hahadu\PostmanApi\Postman;
use Hahadu\ApiDoc\Extracting\Strategies\UrlParameters;
use Illuminate\Routing\UrlGenerator;

class PostmanCollectionWriter
{
    private Collection $routeGroups;

    private string $baseUrl;

    private string $protocol;

    private ?array $auth;

    private string $postmanSchema = "https://schema.getpostman.com/json/collection/v2.1.0/collection.json";

    protected ?Postman $postman = null;

    public function __construct(Collection $routeGroups, $baseUrl)
    {
        $this->routeGroups = $routeGroups;
        $this->baseUrl = $this->getBaseUrl($baseUrl);
        $this->protocol = $this->makeProtocol();
        $this->auth = config('apidoc.postman.auth');
        if (config('apidoc.postman.api_keys')) {
            $this->postman = new Postman(config('apidoc.postman.api_keys'));
        }
    }

    protected function makeProtocol(): string
    {
        return config('apidoc.postman.protocol', 'http');
    }

    public function getCollection(): string
    {
        $apiDocName = config('apidoc.postman.name') ?: config('app.name') . ' API';
        $collection = collect();
        $collection->offsetSet('info', [
            'name' => $apiDocName,
            '_postman_id' => Uuid::uuid4()->toString(),
            'description' => config('apidoc.postman.description') ?: '',
            'schema' => $this->postmanSchema,
        ]);
        $collection->offsetSet("item", $this->routeGroups->map(function (Collection $routes, $groupName) {
            return [
                'name' => $groupName,
                'description' => $routes->first()['metadata']['groupDescription'],
                'item' => $routes->map(\Closure::fromCallable([$this, 'generateEndpointItem']))->toArray(),
                'auth' => $routes->map(\Closure::fromCallable([$this, 'generateAuthItem']))->unique()->first(),
                'event' => [
                    [
                        "listen" => "prerequest",
                        "script" => [
                            "type" => "text/javascript",
                            "exec" => [
                                ""
                            ]
                        ]
                    ],
                    [
                        "listen" => "test",
                        "script" => [
                            "type" => "text/javascript",
                            "exec" => [
                                ""
                            ]
                        ]
                    ]
                ],
            ];
        })->values());

        if (! empty($this->auth)) {
            $collection->offsetSet('auth', $this->auth);
        }
        if ($this->postman instanceof Postman) {
            $old = $this->postman->collections()->getList()->where('name', $apiDocName);
            $sendData = collect(['collection' => $collection]);

            if (! $old->isEmpty()) {
                $docInfo = $old->first();
                dump('update', $this->postman->collections()->update($docInfo['uid'], $sendData->toJson()));
            } else {
                dump('create', $this->postman->collections()->create($sendData->toJson()));
            }
        }

        return $collection->toJson(JSON_PRETTY_PRINT);
    }

    protected function generateAuthItem($route): ?array
    {
        if ($this->getAuth()) {
            return $this->getAuth();
        }

        $position = strrpos($route['headers']['Authorization'], 'Bearer ');

        if ($position !== false) {
            $header = substr($route['headers']['Authorization'], $position + 7);

            $bearToken = str_contains($header, ',') ? strstr(',', $header, true) : $header;
            $type = "bearer";
            $type_param = [[
                'key' => 'token',
                'value' => $bearToken,
                'type' => 'string',
            ]];
            return [
                'type' => $type,
                $type => $type_param,
            ];
        }

        return null;
    }

    protected function generateEndpointItem($route): array
    {
        $mode = 'raw';

        $formdataRawParameters = function ($cleanBodyParameters) {
            $parameters = [];
            foreach ($cleanBodyParameters as $key => $value) {
                $parameters[] = [
                    "key" => $key,
                    "value" => $value,
                    "type" => "text",
                ];
            }
            return $parameters;
        };
        if ($mode == 'formdata') {
            $modeRawParameters = json_encode($formdataRawParameters($route['cleanBodyParameters']));
        } else {
            $modeRawParameters = json_encode($route['cleanBodyParameters'], JSON_UNESCAPED_UNICODE);
        }

        $method = $route['methods'][0];

        return [
            'name' => $route['metadata']['title'] != '' ? $route['metadata']['title'] : $route['uri'],
            'request' => [
                'method' => $method,
                'header' => $this->resolveHeadersForRoute($route),
                'body' => [
                    'mode' => $mode,
                    $mode => $modeRawParameters,
                ],
                'url' => $this->makeUrlData($route),
                'description' => $route['metadata']['description'] ?? null,
                'response' => [],
            ],
        ];
    }

    protected function resolveHeadersForRoute($route): array
    {
        $headers = collect($route['headers']);

        $authHeader = $this->getAuthHeader();
        if (! empty($authHeader)) {
            $headers = $headers->except($authHeader);
        }

        return $headers
            ->union([
                'Accept' => 'application/json',
            ])
            ->map(function ($value, $header) {
                return [
                    'key' => $header,
                    'value' => $value,
                ];
            })
            ->values()
            ->all();
    }

    protected function makeUrlData($route): array
    {
        $urlParams = collect($route['urlParameters'])->filter(function ($_, $key) use ($route) {
            return Str::contains($route['uri'], '{' . $key . '}');
        });

        /** @var Collection $queryParams */
        $base = [
            'protocol' => $this->protocol,
            'host' => $this->baseUrl,
            'raw' => preg_replace_callback('/\/{(\w+)\??}(?=\/|$)/', function ($matches) {
                return '/:' . $matches[1];
            }, $route['uri']),
            'path' => explode("/", preg_replace_callback('/\/{(\w+)\??}(?=\/|$)/', function ($matches) {
                return '/:' . $matches[1];
            }, $route['uri'])),
            'query' => collect($route['queryParameters'])->map(function ($parameter, $key) {
                return [
                    'key' => $key,
                    'value' => $parameter['value'],
                    'description' => $parameter['description'],
                    'disabled' => ! $parameter['required'] && empty($parameter['value']),
                ];
            })->values()->toArray(),
        ];

        /** @var $urlParams Collection */
        if ($urlParams->isEmpty()) {
            return $base;
        }

        $base['variable'] = $urlParams->map(function ($parameter, $key) {
            return [
                'id' => $key,
                'key' => $key,
                'value' => urlencode($parameter['value']),
                'description' => $parameter['description'],
            ];
        })->values()->toArray();

        return $base;
    }

    protected function getAuth(): ?array
    {
        $auth = $this->auth;
        if (empty($auth) || ! is_string($auth['type'] ?? null)) {
            return null;
        }

        return match ($auth['type']) {
            'bearer' => [
                'type' => 'bearer',
                'bearer' => [[
                    'key' => 'token',
                    'value' => $auth['value'],
                    'type' => 'string',
                ]],
            ],
            default => null,
        };
    }

    protected function getAuthHeader(): ?string
    {
        $auth = $this->auth;
        if (empty($auth) || ! is_string($auth['type'] ?? null)) {
            return null;
        }

        return match ($auth['type']) {
            'apikey' => (isset($auth['apikey']['in']) && $auth['apikey']['in'] !== 'header')
                ? null
                : ($auth['apikey']['key'] ?? null),
            default => null,
        };
    }

    protected function getBaseUrl($baseUrl): string
    {
        if (null != config('apidoc.postman.base_url_host')) {
            return config('apidoc.postman.base_url_host');
        }
        if (Str::contains(app()->version(), 'Lumen')) {
            $reflectionMethod = new ReflectionMethod(UrlGenerator::class, 'getRootUrl');
            $reflectionMethod->setAccessible(true);
            $url = app('url');

            return $reflectionMethod->invokeArgs($url, ['', $baseUrl]);
        }

        return URL::formatRoot('', $baseUrl);
    }
}
