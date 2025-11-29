<?php

namespace App\Http\Controllers\Keuangan;

use App\Http\Controllers\Controller;
use App\Models\Keuangan\MasterNotifikasi;
use Illuminate\Http\Request;

class NotifikasiController extends Controller
{
    public function update(Request $request, $id) {
        $notif = MasterNotifikasi::find($id);
        if ($notif) {
            $notif->status = 0;
            $notif->save();
        }


        return response()->json([
            'success' => true,
            'data' => $notif
        ]);
    }

    public function get(Request $request) {
        $data = MasterNotifikasi::where('status', 1)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $data 
        ]);
    }
}
