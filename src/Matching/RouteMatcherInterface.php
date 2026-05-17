<?php

namespace Hahadu\ApiDoc\Matching;

use Hahadu\ApiDoc\Matching\RouteMatcher\Matcher;

interface RouteMatcherInterface
{
    /** @return Matcher[] */
    public function getRoutes(array $routeRules = [], string $router = 'laravel'): array;
}
