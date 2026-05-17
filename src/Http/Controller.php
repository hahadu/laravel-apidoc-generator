<?php

namespace Hahadu\ApiDoc\Http;

use Illuminate\Support\Facades\Storage;

class Controller
{
    public function html(): \Illuminate\Contracts\View\View
    {
        return view('apidoc.index');
    }

    public function json(): \Illuminate\Http\JsonResponse
    {
        return response()->json(
            json_decode(Storage::disk(config('apidoc.storage'))->get('apidoc/collection.json'))
        );
    }
}
