<?php

namespace App\Http\Controllers\SIKPS;

use App\Http\Controllers\Controller;
use App\Models\SIKPS\DospemPembimbingMahasiswa;
use Illuminate\Http\Request;

class DospemPembimbingMahasiswaController extends Controller
{
    public function getAll(Request $request) {
        $filters = $request->query('filters') ?? [];

        $data = DospemPembimbingMahasiswa::getAll($filters);

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }
}
