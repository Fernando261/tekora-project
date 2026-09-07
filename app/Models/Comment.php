<?php

namespace App\Models;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
        use SoftDeletes;

    protected $fillable = ['post_id','user_id','content','media_path','media_type'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }


public function likes()
{
    return $this->morphMany(\App\Models\Like::class, 'likeable');
}
}
