<?php

namespace App\Http\Controllers;

use App\Support\WmsNavigation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DecaController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('deca.index', [
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }
}
