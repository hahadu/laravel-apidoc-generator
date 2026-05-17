<?php

namespace Hahadu\ApiDoc\Extracting\Strategies\Metadata;

use Illuminate\Routing\Route;
use Hahadu\ApiDoc\Extracting\RouteDocBlocker;
use Hahadu\ApiDoc\Extracting\Strategies\Strategy;
use Hahadu\Reflector\Reflection;
use Hahadu\Reflector\Reflection\Tag;
use ReflectionClass;
use ReflectionMethod;

class GetFromDocBlocks extends Strategy
{
    public function __invoke(Route $route, ReflectionClass $controller, ReflectionMethod $method, array $routeRules, array $context = []): ?array
    {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($route);
        /** @var Reflection $methodDocBlock */
        $methodDocBlock = $docBlocks['method'];
        $classDocBlock = $docBlocks['class'];

        [$routeGroupName, $routeGroupDescription, $routeTitle] = $this->getRouteGroupDescriptionAndTitle($methodDocBlock, $classDocBlock);

        return [
            'groupName' => $routeGroupName,
            'groupDescription' => $routeGroupDescription,
            'title' => $routeTitle ?: $methodDocBlock->getShortDescription(),
            'description' => $methodDocBlock->getLongDescription()->getContents(),
            'authenticated' => $this->getAuthStatusFromDocBlock($classDocBlock->getTags()) ?: $this->getAuthStatusFromDocBlock($methodDocBlock->getTags()),
        ];
    }

    protected function getAuthStatusFromDocBlock(array $tags): bool
    {
        $authTag = collect($tags)
            ->first(function ($tag) {
                return $tag instanceof Tag && strtolower($tag->getName()) === 'authenticated';
            });

        return (bool) $authTag;
    }

    /** @return array The route group name, the group description, and the route title */
    protected function getRouteGroupDescriptionAndTitle(Reflection $methodDocBlock, Reflection $controllerDocBlock): array
    {
        if (! empty($methodDocBlock->getTags())) {
            foreach ($methodDocBlock->getTags() as $tag) {
                if ($tag->getName() === 'group') {
                    $routeGroupParts = explode("\n", trim($tag->getContent()));
                    $routeGroupName = array_shift($routeGroupParts);
                    $routeGroupDescription = trim(implode("\n", $routeGroupParts));

                    if (empty($methodDocBlock->getShortDescription())) {
                        return [$routeGroupName, '', $routeGroupDescription];
                    }

                    return [$routeGroupName, $routeGroupDescription, $methodDocBlock->getShortDescription()];
                }
            }
        }

        foreach ($controllerDocBlock->getTags() as $tag) {
            if ($tag->getName() === 'group') {
                $routeGroupParts = explode("\n", trim($tag->getContent()));
                $routeGroupName = array_shift($routeGroupParts);
                $routeGroupDescription = implode("\n", $routeGroupParts);

                return [$routeGroupName, $routeGroupDescription, $methodDocBlock->getShortDescription()];
            }
        }

        return [$this->config->get('default_group'), '', $methodDocBlock->getShortDescription()];
    }
}
