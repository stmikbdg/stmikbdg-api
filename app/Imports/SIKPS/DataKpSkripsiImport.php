<?php

namespace App\Imports\SIKPS;

use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;

class DataKpSkripsiImport implements ToCollection, WithHeadingRow, WithStartRow
{
    public $rows;

    public function startRow(): int
    {
        return 3; // start reading from the 3rd row
    }

    public function collection(Collection $rows)
    {
        $this->rows = $rows;
    }
}

