<?php

namespace Hahadu\ApiDoc;

use Illuminate\Support\Facades\Route;

class ApiDoc
{
    /**
     * Binds the ApiDoc routes into the controller.
     *
     * @deprecated Use autoload routes instead (`config/apidoc.php`: `laravel > autoload`).
     *
     * @param string $path
     */
    public static function routes(string $path = '/doc'): void
    {
        Route::prefix($path)
            ->namespace('\Hahadu\ApiDoc\Http')
            ->middleware(static::middleware())
            ->group(function () {
                Route::get('/', 'Controller@html')->name('apidoc');
                Route::get('.json', 'Controller@json')->name('apidoc.json');
            });
    }

    /**
     * Get the middlewares for Laravel routes.
     *
     * @return array
     */
    protected static function middleware(): array
    {
        return config('apidoc.laravel.middleware', []);
    }
}
