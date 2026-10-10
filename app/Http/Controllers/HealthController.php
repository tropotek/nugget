<?php

namespace App\Http\Controllers;

use App\Support\HealthStatus;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(HealthStatus $health): JsonResponse
    {
        $writable = $health->databaseWritable();

        return response()->json([
            'version' => $health->version(),
            'db' => $writable ? 'ok' : 'error',
        ], $writable ? 200 : 503);
    }
}
