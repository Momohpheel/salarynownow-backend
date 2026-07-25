<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Role extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['employer_id', 'name', 'description', 'status'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function employer()
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employer_id', 'name', 'description', 'status'])
            ->useLogName('Role')
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
