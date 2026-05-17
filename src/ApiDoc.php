<?php

namespace Hahadu\ApiDoc;

use Illuminate\Support\Facades\Route;

class ApiDoc
{
    /** @deprecated Use autoload routes instead (`config/apidoc.php`: `laravel > autoload`). */
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

    protected static function middleware(): array
    {
        return config('apidoc.laravel.middleware', []);
    }
}
