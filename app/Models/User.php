<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    protected $attributes = ['role' => 'Độc giả'];

    protected static function booted(): void
    {
        static::created(function (User $user) {
            if ($user->role === 'Độc giả') {
                $profile = new ReaderProfile;
                $profile->reader_code = 'RD'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
                $user->readerProfile()->save($profile);
            }
        });
    }

    public function readerProfile(): HasOne
    {
        return $this->hasOne(ReaderProfile::class);
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_locked',
        'auth_version',
        'remember_token',
        'phone',
        'address',
        'date_of_birth',
        'avatar_path',
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
            'is_locked' => 'boolean',
            'date_of_birth' => 'date',
        ];
    }
}
