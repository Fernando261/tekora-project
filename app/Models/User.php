<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
    'name',
    'age',
    'gender',
    'email',
    'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function providerProfile(): HasOne
    {
        return $this->hasOne(ProviderProfile::class);
    }

    public function serviceInquiries(): HasMany
    {
        return $this->hasMany(ServiceInquiry::class, 'client_user_id');
    }

    public function hasRole(RoleName $role): bool
    {
        return $this->roles()->where('slug', $role->value)->exists();
    }

    public function hasPermission(Permission $permission): bool
    {
        // Consultar la asignación actual, sin confiar en relaciones cacheadas.
        $roles = array_map(fn (RoleName $role) => $role->value, $permission->roles());

        return $this->roles()->whereIn('slug', $roles)->exists();
    }

    public function posts()
{
    return $this->hasMany(Post::class);
}

public function reminders() {
    return $this->hasMany(Reminder::class);
}

public function likes()
{
    return $this->hasMany(Like::class);
}

public function comments()
{
    return $this->hasMany(Comment::class);
}


}
