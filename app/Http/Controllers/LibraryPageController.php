<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class LibraryPageController extends Controller
{
    public function __invoke(): View
    {
        return view('library.index');
    }
}
