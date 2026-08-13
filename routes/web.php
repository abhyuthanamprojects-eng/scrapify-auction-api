<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| API documentation
|--------------------------------------------------------------------------
| Swagger UI (loaded from the unpkg CDN) rendered against the OpenAPI spec
| checked into docs/openapi.yaml. The spec itself is served as a raw file
| response so Swagger UI can fetch it client-side.
*/
Route::get('/api/docs', function () {
    return view('swagger');
})->name('api.docs');

Route::get('/api/v1/openapi.yaml', function () {
    return response()
        ->file(base_path('docs/openapi.yaml'), ['Content-Type' => 'application/yaml']);
})->name('api.docs.spec');
