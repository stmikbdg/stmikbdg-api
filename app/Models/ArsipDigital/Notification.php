<?php

namespace App\Models\ArsipDigital;

class Notification extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.notifications';
    protected $primaryKey = 'notification_id';

    protected $fillable = [
        'recipient_user_id',
        'recipient_role',
        'type',
        'title',
        'message',
        'entity_type',
        'entity_id',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];
}
