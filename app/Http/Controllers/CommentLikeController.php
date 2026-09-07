<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use Illuminate\Http\Request;

class CommentLikeController extends Controller
{
    public function store(Request $request, Comment $comment)
{
    $userId = $request->user()->id;

    $existing = $comment->likes()->where('user_id', $userId)->first();

    if ($existing) $existing->delete();
    else $comment->likes()->create(['user_id' => $userId]);

    return back();
}
}