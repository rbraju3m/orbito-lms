<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
| This is an API-only application; the SPA is served separately from ../web.
| The root route exists so hitting the backend directly is self-explanatory
| rather than a 404.
*/

Route::get('/', fn (): JsonResponse => new JsonResponse([
    'data' => [
        'name' => config('app.name').' API',
        'version' => config('orbito.version'),
        'docs' => url('/api/v1/health'),
    ],
]));
