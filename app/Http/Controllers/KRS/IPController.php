<?php

namespace App\Http\Controllers\KRS;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\KRS\KRS;
// ? Models
use App\Models\KRS\NilaiAkhirView;
// ? Models - table
use App\Models\Users\Mahasiswa;
// ? Models - view
use App\Services\KRS\AcademicRecordSummaryService;
use Illuminate\Http\Request;

class IPController extends Controller
{
    public function getIPSemester(Request $request, AcademicRecordSummaryService $summaryService)
    {
        try {
            $user = $this->getUserAuth(); // get data mhs yg req
            $listNilai = NilaiAkhirView::getNilaiAkhirByMhsId($user['mhs_id']);
            $collectionListNilai = $listNilai;
            $countTotal = count($listNilai);

            if ($countTotal > 0) {
                $groupedBySemester = $collectionListNilai->groupBy(function ($matkul) {
                    return $matkul['matakuliah']['semester'];
                });

                // ? jika terdapat query 's'
                if ($request->query('s')) {
                    $semester = (string) $request->query('s');
                    $filteredBySemester = self::getIPBySemester($semester, $groupedBySemester);

                    return response()->json([
                        'status' => 'success',
                        'data' => $filteredBySemester,
                    ]);
                }

                // nilai keseluruhan
                $data = $summaryService->summarize($collectionListNilai);

                $tempIPSemester = [];

                foreach ($groupedBySemester->toArray() as $index => $item) {
                    $countItem = count($item);
                    $collectionSemester = collect($item);
                    $countMutuSemester = $collectionSemester->sum('mutu');
                    $countNilaiSemester = $collectionSemester->countBy('nilai');

                    $countTotalSksSemester = $collectionSemester->sum(function ($matkul) {
                        return $matkul['matakuliah']['sks'];
                    });

                    $ipSemester = [
                        'semester' => $index,
                        'total_sks' => $countTotalSksSemester,
                        'total_ip' => ((float) $countMutuSemester / $countItem),
                        'total_nilai_a' => isset($countNilaiSemester['A']) ? $countNilaiSemester['A'] : 0,
                        'total_nilai_b' => isset($countNilaiSemester['B']) ? $countNilaiSemester['B'] : 0,
                        'total_nilai_c' => isset($countNilaiSemester['C']) ? $countNilaiSemester['C'] : 0,
                        'total_nilai_d' => isset($countNilaiSemester['D']) ? $countNilaiSemester['D'] : 0,
                        'total_nilai_e' => isset($countNilaiSemester['E']) ? $countNilaiSemester['E'] : 0,
                    ];

                    array_push($tempIPSemester, $ipSemester);
                }

                $data['ip_per_semester'] = $tempIPSemester;

                return response()->json([
                    'status' => 'success',
                    'data' => $data,
                ]);
            }

            return response()->json([
                'status' => 'success',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
    //Ini titipan 
    public function getListKRSMahasiswaAll(Request $request)
{
    try {

        $tahunId = $request->query('tahun_id');

        $data = Mahasiswa::with('krs')
            ->when($tahunId, function ($query) use ($tahunId) {
                $query->where('tahun_id', $tahunId);
            })
            ->get();

        // hanya mahasiswa yang punya krs
        $data = $data->filter(function ($item) {
            return count($item->krs) > 0;
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $data
        ]);

    } catch (\Exception $e) {
        return ErrorHandler::handle($e);
    }
}

    // Ini titipan
    public function getListKRSMahasiswaAll(Request $request)
    {
        try {

            $tahunId = $request->query('tahun_id');

            $data = Mahasiswa::with('krs')
                ->when($tahunId, function ($query) use ($tahunId) {
                    $query->where('tahun_id', $tahunId);
                })
                ->get();

            // hanya mahasiswa yang punya krs
            $data = $data->filter(function ($item) {
                return count($item->krs) > 0;
            })->values();

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function getIPBySemester(string $semester, mixed $ipSemester)
    {
        $ipSemesterArr = $ipSemester->toArray();

        if (array_key_exists($semester, $ipSemesterArr)) {
            $tempData = $ipSemesterArr[$semester];

            // nilai keseluruhan dalam satu semester
            $collectionData = collect($tempData);
            $countTotalItem = $collectionData->count();
            $countTotalMutu = $collectionData->sum('mutu');
            $countTotalNilai = $collectionData->countBy('nilai');

            $countTotalSks = $collectionData->sum(function ($matkul) {
                return $matkul['matakuliah']['sks'];
            });

            $data = [
                'semester' => (int) $semester,
                'total_sks' => $countTotalSks,
                'total_ip' => ((float) $countTotalMutu / $countTotalItem),
                'total_nilai_a' => isset($countTotalNilai['A']) ? $countTotalNilai['A'] : 0,
                'total_nilai_b' => isset($countTotalNilai['B']) ? $countTotalNilai['B'] : 0,
                'total_nilai_c' => isset($countTotalNilai['C']) ? $countTotalNilai['C'] : 0,
                'total_nilai_d' => isset($countTotalNilai['D']) ? $countTotalNilai['D'] : 0,
                'total_nilai_e' => isset($countTotalNilai['E']) ? $countTotalNilai['E'] : 0,
            ];

            $tempMatkul = [];

            foreach ($tempData as $index => $item) {
                $newItem = [
                    'nm_mk' => trim($item['matakuliah']['nm_mk']),
                    'kd_mk' => trim($item['matakuliah']['kd_mk']),
                    'sks' => $item['matakuliah']['sks'],
                    'nilai' => $item['nilai'],
                    'mutu' => $item['mutu'],
                ];

                array_push($tempMatkul, $newItem);
            }

            $data['matakuliah'] = $tempMatkul;
        } else {
            return $data = [
                'semester' => (int) $semester,
                'total_sks' => 0,
                'total_ip' => 0,
                'total_nilai_a' => 0,
                'total_nilai_b' => 0,
                'total_nilai_c' => 0,
                'total_nilai_d' => 0,
                'total_nilai_e' => 0,
            ];
        }

        return $data;
    }
}
