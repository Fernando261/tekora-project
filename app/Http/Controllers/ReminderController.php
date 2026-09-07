<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function store(Request $request)
{
    $request->validate([
        'title' => 'required',
        'remind_at' => 'required|date',
    ]);

    Reminder::create([
        'user_id' => Auth::id(),
        'title' => $request->input('title'),
        'description' => $request->input('description'),
        'remind_at' => $request->input('remind_at'),
    ]);

    return redirect()->back()->with('success', 'Recordatorio creado.');
}

}
