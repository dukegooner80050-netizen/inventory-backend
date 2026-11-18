<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /** The one active Academic Coordinator (other than $exceptId), or null. There may be only one at a time. */
    public static function activeAcademicCoordinator($exceptId = null)
    {
        return static::whereRaw('LOWER(role) = ?', ['academic_coordinator'])
            ->where('status', 'active')
            ->when($exceptId, fn ($q) => $q->where('idUsers', '!=', $exceptId))
            ->first();
    }

    public static function coordinatorConflictMessage($other): string
    {
        return "There is already an active Academic Coordinator ({$other->full_name}). Only one can be active, so deactivate them or change their role first.";
    }

    /**
     * Table name
     */
    protected $table = 'users';

    /**
     * Primary Key
     */
    protected $primaryKey = 'idUsers';

    /**
     * Auto Increment
     */
    public $incrementing = true;

    /**
     * Primary key type
     */
    protected $keyType = 'int';

    /**
     * Mass assignable
     */
    protected $fillable = [
        'full_name',
        'username',
        'password',
        'role',
        'iddept',
    ];

    /**
     * Hidden fields
     */
    protected $hidden = [
        'password',
        'email_code_hash',
        'email_code_expires_at',
        'email_code_sent_at',
        'email_code_attempts',
    ];

    /**
     * Casts
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'email_verified_at' => 'datetime',
            'email_code_expires_at' => 'datetime',
            'email_code_sent_at' => 'datetime',
        ];
    }
    
    public function requests()
{
    return $this->hasMany(Request::class, 'idUsers', 'idUsers');
}

public function transactions()
{
    return $this->hasMany(Transaction::class, 'idUsers', 'idUsers');
}

public function logs()
{
    return $this->hasMany(Log::class, 'idUsers', 'idUsers');
}
}