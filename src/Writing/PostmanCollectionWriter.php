<?php

namespace Hahadu\ApiDoc\Writing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use Hahadu\PostmanApi\Postman;
class PostmanCollectionWriter
{
    /**
     * @var Collection
     */
    private $routeGroups;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var string
     */
    private $protocol;

    /**
     * @var array|null
     */
    private $auth;
    private $postmanSchema = "https://schema.getpostman.com/json/collection/v2.1.0/collection.json";

    /**
     * @var Postman
     */
    protected $postman;
    /**
     * CollectionWriter constructor.
     *
     * @param Collection $routeGroups
     */
    public function __construct(Collection $routeGroups, $baseUrl)
    {
        $this->routeGroups = $routeGroups;
        $this->protocol = Str::startsWith($baseUrl, 'https') ? 'https' : 'http';
        $this->baseUrl = $this->getBaseUrl($baseUrl);
        $this->auth = config('apidoc.postman.auth');
        if(config('apidoc.postman.api_keys')){
            $this->postman = new Postman(config('apidoc.postman.api_keys'));
        }
    }


    /**
     * 全部数据
     * @return false|string
     */
    public function getCollection()
    {

        $apiDocName = config('apidoc.postman.name') ?: config('app.name') . ' API';
        $collection = [
        //    'variables' => [],
            'info' => [
                'name' => $apiDocName,
                '_postman_id' => Uuid::uuid4()->toString(),
                'description' => config('apidoc.postman.description') ?: '',
                'schema' => $this->postmanSchema,
            ],
            'item' => $this->routeGroups->map(function (Collection $routes, $groupName) {
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
            })->values()->toArray(),
        ];

        if (! empty($this->auth)) {
            $collection['auth'] = $this->auth;
        }
        if($this->postman instanceof Postman){
            $old = $this->postman->collections()->getList()->where('name',$apiDocName);
            $sendData = json_encode(['collection'=>$collection]);
            if(!$old->isEmpty()){
                $docInfo = $old->first();
                dump('update',$this->postman->collections()->update($docInfo['uid'],$sendData));
            }
            dump('create',$this->postman->collections()->create($sendData));
        }

        return json_encode($collection, JSON_PRETTY_PRINT);
    }


    protected function generateAuthItem($route){

        if($this->getAuth()){
            return $this->getAuth();
        }else{

            $position = strrpos($route['headers']['Authorization'], 'Bearer ');

            if ($position !== false) {
                $header = substr($route['headers']['Authorization'], $position + 7);

                $bearToken = strpos($header, ',') !== false ? strstr(',', $header, true) : $header;
                $type = "bearer";
                $type_param = [[
                    'key'=>'token',
                    'value'=> $bearToken,
                    'type'=> 'string'
                ]];
                return [
                    'type'=>$type,
                    $type => $type_param,
                ];
            }
        }
    }

    /**
     *
     * @param $route
     * @return array
     */
    protected function generateEndpointItem($route)
    {
        $mode = 'raw';

        $formdataRawParameters = function ($cleanBodyParameters){
            $parameters = [];
            foreach ($cleanBodyParameters as $key =>$value){
                $parameters[] = [
                    "key"=>$key,
                    "value"=>$value,
                    "type"=>"text"
                ];
            }
            return $parameters;
        };
        if($mode=='formdata'){
        //    $modeRawParameters = $formdataRawParameters($route['cleanBodyParameters']);
            $modeRawParameters = json_encode($formdataRawParameters($route['cleanBodyParameters']));
        }else{
            $modeRawParameters = json_encode($route['cleanBodyParameters'],JSON_UNESCAPED_UNICODE);
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

    /**
     * headers
     * @param $route
     * @return array
     */
    protected function resolveHeadersForRoute($route)
    {
        $headers = collect($route['headers']);

        // Exclude authentication headers if they're handled by Postman auth
        $authHeader = $this->getAuthHeader();
        //dump($authHeader);
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

    /**
     * request url data
     * @param $route
     * @return array
     */
    protected function makeUrlData($route)
    {
        // URL Parameters are collected by the `UrlParameters` strategies, but only make sense if they're in the route
        // definition. Filter out any URL parameters that don't appear in the URL.
        $urlParams = collect($route['urlParameters'])->filter(function ($_, $key) use ($route) {
            return Str::contains($route['uri'], '{' . $key . '}');
        });

        /** @var Collection $queryParams */
        $base = [
            'protocol' => $this->protocol,
            'host' => $this->baseUrl,
            // Substitute laravel/symfony query params ({example}) to Postman style, prefixed with a colon
            'raw' => preg_replace_callback('/\/{(\w+)\??}(?=\/|$)/', function ($matches) {
                return '/:' . $matches[1];
            }, $route['uri']),
            'path' => explode("/",preg_replace_callback('/\/{(\w+)\??}(?=\/|$)/', function ($matches) {
                return '/:' . $matches[1];
            }, $route['uri'])),
            'query' => collect($route['queryParameters'])->map(function ($parameter, $key) {
                return [
                    'key' => $key,
                    'value' => urlencode($parameter['value']),
                    'description' => $parameter['description'],
                    // Default query params to disabled if they aren't required and have empty values
                    'disabled' => ! $parameter['required'] && empty($parameter['value']),
                ];
            })->values()->toArray(),
        ];

        // If there aren't any url parameters described then return what we've got
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

    protected function getAuth(){
        $auth = $this->auth;
        if (empty($auth) || ! is_string($auth['type'] ?? null)) {
            return null;
        }

        $type = $auth['type'];
        switch ($auth['type']) {
            case 'bearer':
                $type_param = [
                    'key'=>'token',
                    'value'=> $auth['value'],
                    'type'=> 'string'
                ];
                return [
                    'type'=>$type,
                    $type => [$type_param],
                ];
            case 'apikey':
            default:
                return null;
        }
    }
    protected function getAuthHeader()
    {
        $auth = $this->auth;
        if (empty($auth) || ! is_string($auth['type'] ?? null)) {
            return null;
        }

        switch ($auth['type']) {
            case 'apikey':
                $spec = $auth['apikey'];

                if (isset($spec['in']) && $spec['in'] !== 'header') {
                    return null;
                }

                return $spec['key'];
            case 'bearer':
                //    return 'Authorization';
            default:
                return null;
        }
    }

    protected function getBaseUrl($baseUrl)
    {
        if (Str::contains(app()->version(), 'Lumen')) { //Is Lumen
            $reflectionMethod = new ReflectionMethod(\Laravel\Lumen\Routing\UrlGenerator::class, 'getRootUrl');
            $reflectionMethod->setAccessible(true);
            $url = app('url');

            return $reflectionMethod->invokeArgs($url, ['', $baseUrl]);
        }

        return URL::formatRoot('', $baseUrl);
    }
}
