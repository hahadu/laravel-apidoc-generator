<?php

namespace Hahadu\ApiDoc\Extracting\Strategies;

use Illuminate\Routing\Route;
use Hahadu\ApiDoc\Tools\DocumentationConfig;
use ReflectionClass;
use ReflectionMethod;

abstract class Strategy
{
    protected DocumentationConfig $config;

    protected string $stage;

    public function __construct(string $stage, DocumentationConfig $config)
    {
        $this->stage = $stage;
        $this->config = $config;
    }

    abstract public function __invoke(Route $route, ReflectionClass $controller, ReflectionMethod $method, array $routeRules, array $context = []): ?array;
}
