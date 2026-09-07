<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Emotion;

class HomeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $emotions = Emotion::orderBy('order')->orderBy('id')->get();
        return view('home', compact('emotions'));
    }
}