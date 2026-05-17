<?php

namespace Hahadu\ApiDoc\Matching;

use Illuminate\Routing\Route;

/**
 * Lumen routes don't extend from Laravel routes,
 * so we need this class to convert a Lumen route to a Laravel one.
 */
class LumenRouteAdapter extends Route
{
    public function __construct(array $lumenRoute)
    {
        parent::__construct($lumenRoute['method'], $lumenRoute['uri'], $lumenRoute['action']);
    }
}
