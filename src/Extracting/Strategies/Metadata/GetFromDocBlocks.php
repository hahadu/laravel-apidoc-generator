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
    public function __invoke(Route $route, ReflectionClass $controller, ReflectionMethod $method, array $routeRules, array $context = [])
    {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($route);
        /** @var Reflection $methodDocBlock */
        $methodDocBlock = $docBlocks['method'];
        $classDocBlock = $docBlocks['class'];

        list($routeGroupName, $routeGroupDescription, $routeTitle) = $this->getRouteGroupDescriptionAndTitle($methodDocBlock, $classDocBlock);

        return [
            'groupName' => $routeGroupName,
            'groupDescription' => $routeGroupDescription,
            'title' => $routeTitle ?: $methodDocBlock->getShortDescription(),
            'description' => $methodDocBlock->getLongDescription()->getContents(),
            'authenticated' => $this->getAuthStatusFromDocBlock($classDocBlock->getTags()) ?: $this->getAuthStatusFromDocBlock($methodDocBlock->getTags()),
        ];
    }

    /**
     * @param array $tags Tags in the method doc block
     *
     * @return bool
     */
    protected function getAuthStatusFromDocBlock(array $tags)
    {
        $authTag = collect($tags)
            ->first(function ($tag) {
                return $tag instanceof Tag && strtolower($tag->getName()) === 'authenticated';
            });

        return (bool) $authTag;
    }

    /**
     * @param Reflection $methodDocBlock
     * @param Reflection $controllerDocBlock
     *
     * @return array The route group name, the group description, and the route title
     */
    protected function getRouteGroupDescriptionAndTitle(Reflection $methodDocBlock, Reflection $controllerDocBlock)
    {
        // @group tag on the method overrides that on the controller
        if (! empty($methodDocBlock->getTags())) {
            foreach ($methodDocBlock->getTags() as $tag) {
                if ($tag->getName() === 'group') {
                    $routeGroupParts = explode("\n", trim($tag->getContent()));
                    $routeGroupName = array_shift($routeGroupParts);
                    $routeGroupDescription = trim(implode("\n", $routeGroupParts));

                    // If the route has no title (the methodDocBlock's "short description"),
                    // we'll assume the routeGroupDescription is actually the title
                    // Something like this:
                    // /**
                    //   * Fetch cars. <-- This is route title.
                    //   * @group Cars <-- This is group name.
                    //   * APIs for cars. <-- This is group description (not required).
                    //   **/
                    // VS
                    // /**
                    //   * @group Cars <-- This is group name.
                    //   * Fetch cars. <-- This is route title, NOT group description.
                    //   **/

                    // BTW, this is a spaghetti way of doing this.
                    // It shall be refactored soon. Deus vult!💪
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
