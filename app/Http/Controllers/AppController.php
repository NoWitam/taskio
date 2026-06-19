<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AppController extends Controller
{
    /**
     * Wyświetla główny widok aplikacji z wstrzykniętymi danymi użytkownika i uprawnień
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        return view('app');
    }

    public function next(Request $request)
    {
        return view('next');
    }
}
