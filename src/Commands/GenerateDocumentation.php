<?php

namespace Hahadu\ApiDoc\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Hahadu\ApiDoc\Extracting\Generator;
use Hahadu\ApiDoc\Matching\RouteMatcher\Matcher;
use Hahadu\ApiDoc\Matching\RouteMatcherInterface;
use Hahadu\ApiDoc\Tools\DocumentationConfig;
use Hahadu\ApiDoc\Tools\Flags;
use Hahadu\ApiDoc\Tools\Utils;
use Hahadu\ApiDoc\Writing\Writer;
use Hahadu\Reflector\Reflection;
use ReflectionClass;
use ReflectionException;

class GenerateDocumentation extends Command
{
    protected $signature = 'apidoc:generate
                            {--force : Force rewriting of existing routes}
    ';

    protected $description = 'Generate your API documentation from existing Laravel routes.';

    private DocumentationConfig $docConfig;

    private string $baseUrl;

    public function handle(RouteMatcherInterface $routeMatcher): void
    {
        Flags::$shouldBeVerbose = $this->option('verbose');

        $this->docConfig = new DocumentationConfig(config('apidoc'));
        $this->baseUrl = $this->docConfig->get('base_url') ?? config('app.url');

        URL::forceRootUrl($this->baseUrl);

        $routes = $routeMatcher->getRoutes($this->docConfig->get('routes'), $this->docConfig->get('router'));

        $generator = new Generator($this->docConfig);
        $parsedRoutes = $this->processRoutes($generator, $routes);

        $groupedRoutes = collect($parsedRoutes)
            ->groupBy('metadata.groupName')
            ->sortBy(static function ($group) {
                /* @var $group Collection */
                return $group->first()['metadata']['groupName'];
            }, SORT_NATURAL);
        $writer = new Writer(
            $this,
            $this->docConfig,
            $this->option('force')
        );
        $writer->writeDocs($groupedRoutes);
    }

    /** @param Matcher[] $routes */
    private function processRoutes(Generator $generator, array $routes): array
    {
        $parsedRoutes = [];
        foreach ($routes as $routeItem) {
            $route = $routeItem->getRoute();
            /** @var Route $route */
            $messageFormat = '%s route: [%s] %s';
            $routeMethods = implode(',', $generator->getMethods($route));
            $routePath = $generator->getUri($route);

            if ($this->isClosureRoute($route->getAction())) {
                $this->warn(sprintf($messageFormat, 'Skipping', $routeMethods, $routePath) . ': Closure routes are not supported.');
                continue;
            }

            $routeControllerAndMethod = Utils::getRouteClassAndMethodNames($route->getAction());
            if (! $this->isValidRoute($routeControllerAndMethod)) {
                $this->warn(sprintf($messageFormat, 'Skipping invalid', $routeMethods, $routePath));
                continue;
            }

            if (! $this->doesControllerMethodExist($routeControllerAndMethod)) {
                $this->warn(sprintf($messageFormat, 'Skipping', $routeMethods, $routePath) . ': Controller method does not exist.');
                continue;
            }

            if (! $this->isRouteVisibleForDocumentation($routeControllerAndMethod)) {
                $this->warn(sprintf($messageFormat, 'Skipping', $routeMethods, $routePath) . ': @hideFromAPIDocumentation was specified.');
                continue;
            }

            try {
                $parsedRoutes[] = $generator->processRoute($route, $routeItem->getRules());
                $this->info(sprintf($messageFormat, 'Processed', $routeMethods, $routePath));
            } catch (\Exception $exception) {
                $this->warn(sprintf($messageFormat, 'Skipping', $routeMethods, $routePath) . '- Exception ' . get_class($exception) . ' encountered : ' . $exception->getMessage());
            }
        }

        return $parsedRoutes;
    }

    private function isValidRoute(?array $routeControllerAndMethod): bool
    {
        if (is_array($routeControllerAndMethod)) {
            $routeControllerAndMethod = implode('@', $routeControllerAndMethod);
        }

        return ! is_callable($routeControllerAndMethod) && ! is_null($routeControllerAndMethod);
    }

    private function isClosureRoute(array $routeAction): bool
    {
        return $routeAction['uses'] instanceof \Closure;
    }

    private function doesControllerMethodExist(array $routeControllerAndMethod): bool
    {
        [$class, $method] = $routeControllerAndMethod;
        $reflection = new ReflectionClass($class);

        return $reflection->hasMethod($method);
    }

    private function isRouteVisibleForDocumentation(array $routeControllerAndMethod): bool
    {
        [$class, $method] = $routeControllerAndMethod;
        $reflection = new ReflectionClass($class);

        $tags = collect();

        foreach (
            array_filter([
                $reflection->getDocComment(),
                $reflection->getMethod($method)->getDocComment()
            ]) as $comment
        ) {
            $phpdoc = new Reflection($comment);
            $tags = $tags->concat($phpdoc->getTags());
        }

        return $tags->filter(function ($tag) {
            return $tag->getName() === 'hideFromAPIDocumentation';
        })->isEmpty();
    }
}
