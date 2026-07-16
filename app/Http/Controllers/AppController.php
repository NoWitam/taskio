<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AppController extends Controller
{
    /**
     * Serve the "next" SPA shell. This is the only frontend entry point — the
     * legacy `/app` bundle was decommissioned (its `/app` route now redirects
     * here for old bookmarks).
     */
    public function next(Request $request)
    {
        return view('next');
    }
}
