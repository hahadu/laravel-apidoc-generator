<?php

namespace Hahadu\ApiDoc\Matching\RouteMatcher;

use Illuminate\Routing\Route;

class Matcher implements \ArrayAccess
{
    protected Route $route;

    protected array $rules;

    public function __construct(Route $route, array $applyRules)
    {
        $this->route = $route;
        $this->rules = $applyRules;
    }

    public function getRoute(): Route
    {
        return $this->route;
    }

    public function getRules(): array
    {
        return $this->rules;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_callable([$this, 'get' . ucfirst($offset)]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return call_user_func([$this, 'get' . ucfirst($offset)]);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->$offset = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->$offset = null;
    }
}
