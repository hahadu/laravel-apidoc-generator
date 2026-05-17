<?php

namespace Hahadu\ApiDoc\Extracting\Strategies\Responses;

use Illuminate\Routing\Route;
use Hahadu\ApiDoc\Extracting\RouteDocBlocker;
use Hahadu\ApiDoc\Extracting\Strategies\Strategy;
use Hahadu\Reflector\Reflection;
use Hahadu\Reflector\Reflection\Tag;
use ReflectionClass;
use ReflectionMethod;

/**
 * Get a response from from a file in the docblock ( @responseFile ).
 */
class UseResponseFileTag extends Strategy
{
    public function __invoke(Route $route, ReflectionClass $controller, ReflectionMethod $method, array $routeRules, array $context = []): ?array
    {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($route);
        /** @var Reflection $methodDocBlock */
        $methodDocBlock = $docBlocks['method'];

        return $this->getFileResponses($methodDocBlock->getTags());
    }

    protected function getFileResponses(array $tags): ?array
    {
        $responseFileTags = array_values(
            array_filter($tags, function ($tag) {
                return $tag instanceof Tag && strtolower($tag->getName()) === 'responsefile';
            })
        );

        if (empty($responseFileTags)) {
            return null;
        }

        return array_map(function (Tag $responseFileTag) {
            preg_match('/^(\d{3})?\s?([\S]*[\s]*?)(\{.*\})?$/', $responseFileTag->getContent(), $result);
            $relativeFilePath = trim($result[2]);
            $filePath = storage_path($relativeFilePath);
            if (! file_exists($filePath)) {
                throw new \Exception('@responseFile ' . $relativeFilePath . ' does not exist');
            }
            $status = $result[1] ?: 200;
            $content = $result[2] ? file_get_contents($filePath, true) : '{}';
            $json = ! empty($result[3]) ? str_replace("'", '"', $result[3]) : '{}';
            $merged = array_merge(json_decode($content, true), json_decode($json, true));

            return ['content' => json_encode($merged), 'status' => (int) $status];
        }, $responseFileTags);
    }
}
