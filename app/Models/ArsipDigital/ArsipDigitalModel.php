<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\Model;

abstract class ArsipDigitalModel extends Model
{
    protected $connection;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->connection = config('myconfig.database.first_connection');
    }
}
