<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LibraryPageController extends Controller
{
    public function ready(): JsonResponse
    {
        try {
            DB::selectOne('SELECT 1');
        } catch (\Throwable) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ready']);
    }

    public function __invoke(): View
    {
        return view('library.index');
    }
}
