<?php

namespace App\Http\Controllers\Surat_V2;

use App\Http\Controllers\Controller;
use App\Models\Surat_V2\MasterSurat;
use Illuminate\Http\Request;

class MasterSuratController extends Controller
{
    public function masterSurat_getAll(Request $request) {
        $data = MasterSurat::getAllMasterSurat()->toArray();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function masterSurat_create(Request $request) {
        try {
            $request->validate([
                'nama_surat' => 'required|string',
                'isi_surat' => 'required|string'
            ]);
            
            $body = $request->only((new MasterSurat)->getFillable());

            $data = MasterSurat::create($body);
    
            return response()->json([
                'success' => true,
                'message' => 'Data master surat berhasil ditambahkan.',
                'data' => $data
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }

    public function masterSurat_update(Request $request, int $id) {
        try {
            $request->validate([
                'nama_surat' => 'required|string',
                'isi_surat' => 'required|string'
            ]);
    
            $payload = $request->only((new MasterSurat)->getFillable());
    
            $data = MasterSurat::findOrFail($id);

            $data->update($payload);
    
            return response()->json([
                'success' => true,
                'message' => 'Data master surat berhasil diubah.',
                'data' => $data
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.',
            ], 404);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }

    public function masterSurat_delete(Request $request, int $id) {
        try {
    
            $data = MasterSurat::findOrFail($id);

            $data->delete();
    
            return response()->json([
                'success' => true,
                'message' => 'Data master surat berhasil dihapus.',
                'data' => $data
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.',
                'error' => $e
            ], 404);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 500);
        }
    }
}
