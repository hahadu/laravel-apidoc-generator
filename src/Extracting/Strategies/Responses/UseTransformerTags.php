<?php

namespace Hahadu\ApiDoc\Extracting\Strategies\Responses;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as IlluminateModel;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use League\Fractal\Manager;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use Hahadu\ApiDoc\Extracting\RouteDocBlocker;
use Hahadu\ApiDoc\Extracting\Strategies\Strategy;
use Hahadu\ApiDoc\Tools\Flags;
use Hahadu\ApiDoc\Tools\Utils;
use Hahadu\Reflector\Reflection;
use Hahadu\Reflector\Reflection\Tag;
use ReflectionClass;
use ReflectionMethod;

/**
 * Parse a transformer response from the docblock ( @transformer || @transformercollection ).
 */
class UseTransformerTags extends Strategy
{
    public function __invoke(Route $route, ReflectionClass $controller, ReflectionMethod $method, array $routeRules, array $context = []): ?array
    {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($route);
        /** @var Reflection $methodDocBlock */
        $methodDocBlock = $docBlocks['method'];

        return $this->getTransformerResponse($methodDocBlock->getTags(), $route);
    }

    protected function getTransformerResponse(array $tags, Route $route): ?array
    {
        try {
            if (empty($transformerTag = $this->getTransformerTag($tags))) {
                return null;
            }

            [$statusCode, $transformer] = $this->getStatusCodeAndTransformerClass($transformerTag);
            $model = $this->getClassToBeTransformed($tags, (new ReflectionClass($transformer))->getMethod('transform'));
            $modelInstance = $this->instantiateTransformerModel($model);

            $fractal = new Manager();

            if (! is_null(config('apidoc.fractal.serializer'))) {
                $fractal->setSerializer(app(config('apidoc.fractal.serializer')));
            }

            $resource = (strtolower($transformerTag->getName()) == 'transformercollection')
                ? new Collection(
                    [$modelInstance, $this->instantiateTransformerModel($model)],
                    new $transformer()
                )
                : new Item($modelInstance, new $transformer());

            $response = response($fractal->createData($resource)->toJson());

            return [
                [
                    'status' => $statusCode ?: $response->getStatusCode(),
                    'content' => $response->getContent(),
                ],
            ];
        } catch (Exception $e) {
            echo 'Exception thrown when fetching transformer response for [' . implode(',', $route->methods) . "] {$route->uri}.\n";
            if (Flags::$shouldBeVerbose) {
                Utils::dumpException($e);
            } else {
                echo "Run this again with the --verbose flag to see the exception.\n";
            }

            return null;
        }
    }

    private function getStatusCodeAndTransformerClass(Tag $tag): array
    {
        $content = $tag->getContent();
        preg_match('/^(\d{3})?\s?([\s\S]*)$/', $content, $result);
        $status = $result[1] ?: 200;
        $transformerClass = $result[2];

        return [$status, $transformerClass];
    }

    private function getClassToBeTransformed(array $tags, ReflectionMethod $transformerMethod): string
    {
        $modelTag = Arr::first(array_filter($tags, function ($tag) {
            return ($tag instanceof Tag) && strtolower($tag->getName()) == 'transformermodel';
        }));

        $type = null;
        if ($modelTag) {
            $type = $modelTag->getContent();
        } else {
            $parameter = Arr::first($transformerMethod->getParameters());
            if ($parameter->hasType() && ! $parameter->getType()->isBuiltin() && class_exists($parameter->getType()->getName())) {
                $type = $parameter->getType()->getName();
            }
        }

        if ($type == null) {
            throw new Exception('Failed to detect a transformer model. Please specify a model using @transformerModel.');
        }

        return $type;
    }

    protected function instantiateTransformerModel(string $type): object
    {
        try {
            $type = ltrim($type, '\\');

            return factory($type)->make();
        } catch (Exception $e) {
            if (Flags::$shouldBeVerbose) {
                echo "Eloquent model factory failed to instantiate {$type}; trying to fetch from database.\n";
            }

            $instance = new $type();
            if ($instance instanceof IlluminateModel) {
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

    private function getTransformerTag(array $tags): ?Tag
    {
        $transformerTags = array_values(
            array_filter($tags, function ($tag) {
                return ($tag instanceof Tag) && in_array(strtolower($tag->getName()), ['transformer', 'transformercollection']);
            })
        );

        return Arr::first($transformerTags);
    }
}
