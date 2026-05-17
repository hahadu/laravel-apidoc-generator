<?php

namespace Hahadu\ApiDoc\Extracting\Strategies\Responses;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Hahadu\ApiDoc\Extracting\RouteDocBlocker;
use Hahadu\ApiDoc\Extracting\Strategies\Strategy;
use Hahadu\ApiDoc\Tools\Flags;
use Hahadu\ApiDoc\Tools\Utils;
use Hahadu\Reflector\Reflection;
use Hahadu\Reflector\Reflection\Tag;
use ReflectionClass;
use ReflectionMethod;

/**
 * Parse an Eloquent API resource response from the docblock ( @apiResource || @apiResourcecollection ).
 */
class UseApiResourceTags extends Strategy
{
    public function __invoke(Route $route, ReflectionClass $controller, ReflectionMethod $method, array $routeRules, array $context = []): ?array
    {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($route);
        /** @var Reflection $methodDocBlock */
        $methodDocBlock = $docBlocks['method'];

        return $this->getApiResourceResponse($methodDocBlock->getTags(), $route);
    }

    protected function getApiResourceResponse(array $tags, Route $route): ?array
    {
        try {
            if (empty($apiResourceTag = $this->getApiResourceTag($tags))) {
                return null;
            }

            [$statusCode, $apiResourceClass] = $this->getStatusCodeAndApiResourceClass($apiResourceTag);
            $model = $this->getClassToBeTransformed($tags);
            $modelInstance = $this->instantiateApiResourceModel($model);

            try {
                $resource = new $apiResourceClass($modelInstance);
            } catch (Exception $e) {
                $resource = new $apiResourceClass(collect([$modelInstance]));
            }
            if (strtolower($apiResourceTag->getName()) == 'apiresourcecollection') {
                $models = [$modelInstance, $this->instantiateApiResourceModel($model)];
                $resource = $resource instanceof ResourceCollection
                    ? new $apiResourceClass(collect($models))
                    : $apiResourceClass::collection(collect($models));
            }

            /** @var Response $response */
            $response = $resource->toResponse(app(Request::class));

            return [
                [
                    'status' => $statusCode ?: $response->getStatusCode(),
                    'content' => $response->getContent(),
                ],
            ];
        } catch (Exception $e) {
            echo 'Exception thrown when fetching Eloquent API resource response for [' . implode(',', $route->methods) . "] {$route->uri}.\n";
            if (Flags::$shouldBeVerbose) {
                Utils::dumpException($e);
            } else {
                echo "Run this again with the --verbose flag to see the exception.\n";
            }

            return null;
        }
    }

    private function getStatusCodeAndApiResourceClass(Tag $tag): array
    {
        $content = $tag->getContent();
        preg_match('/^(\d{3})?\s?([\s\S]*)$/', $content, $result);
        $status = $result[1] ?: 0;
        $apiResourceClass = $result[2];

        return [$status, $apiResourceClass];
    }

    private function getClassToBeTransformed(array $tags): string
    {
        $modelTag = Arr::first(array_filter($tags, function ($tag) {
            return ($tag instanceof Tag) && strtolower($tag->getName()) == 'apiresourcemodel';
        }));

        $type = $modelTag->getContent();

        if (empty($type)) {
            throw new Exception('Failed to detect an Eloquent API resource model. Please specify a model using @apiResourceModel.');
        }

        return $type;
    }

    protected function instantiateApiResourceModel(string $type): object
    {
        try {
            $type = ltrim($type, '\\');

            return factory($type)->make();
        } catch (Exception $e) {
            if (Flags::$shouldBeVerbose) {
                echo "Eloquent model factory failed to instantiate {$type}; trying to fetch from database.\n";
            }

            $instance = new $type();
            if ($instance instanceof Model) {
                try {
                    $firstInstance = $type::first();
                    if ($firstInstance) {
                        return $firstInstance;
                    }
                } catch (Exception $e) {
                    if (Flags::$shouldBeVerbose) {
                        echo "Failed to fetch first {$type} from database; using `new` to instantiate.\n";
                    }
                }
            }
        }

        return $instance;
    }

    private function getApiResourceTag(array $tags): ?Tag
    {
        $apiResourceTags = array_values(
            array_filter($tags, function ($tag) {
                return ($tag instanceof Tag) && in_array(strtolower($tag->getName()), ['apiresource', 'apiresourcecollection']);
            })
        );

        return Arr::first($apiResourceTags);
    }
}
