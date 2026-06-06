<?php

namespace App\Http\Controllers;

use App\Support\WmsNavigation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ModulePlaceholderController extends Controller
{
    public function __invoke(Request $request): View
    {
        $moduleKey = (string) $request->route('module');
        $module = WmsNavigation::module($moduleKey);

        abort_if($module === null, 404);

        return view('modules.placeholder', [
            'module' => $module,
            'navigationItems' => WmsNavigation::forUser($request->user()),
        ]);
    }
}
