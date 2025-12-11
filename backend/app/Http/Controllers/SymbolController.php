<?php

namespace App\Http\Controllers;

use App\Http\Resources\SymbolResource;
use App\Models\Symbol;
use Illuminate\Http\JsonResponse;

class SymbolController extends Controller
{
    /**
     * List all active trading symbols.
     */
    public function index(): JsonResponse
    {
        $symbols = Symbol::where('is_active', true)
            ->orderBy('symbol')
            ->get();

        return response()->json([
            'symbols' => SymbolResource::collection($symbols),
        ]);
    }
}
