<?php

namespace App\Http\Controllers\KRS;

use App\Exceptions\ErrorHandler;
use App\Exports\RekapExport;
use App\Http\Controllers\Controller;
use App\Models\KRS\KRS;
use App\Models\KRS\KRSMatkul;
use App\Models\KRS\MatkulDiselenggarakanView;
use App\Models\KRS\NilaiAkhirView;
use App\Models\TahunAjaranView;
use App\Models\Users\Dosen;
// ? Models - table
use App\Models\Users\Mahasiswa;
use App\Models\Users\MahasiswaView;
use Illuminate\Http\Request;
// ? Models - view
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KRSDosenController extends Controller
{
    private $user;

    public function __construct()
    {
        $this->user = $this->getUserAuth();
    }

    public function getKRSMahasiswa(Request $request)
    {
        try {
            // is user dosen wali?
            self::getStatusDosenWali($request);

            $mahasiswa = $this->user->mahasiswa()->where('mhs_id', $request->mhs_id)->first();
            $jurusanMahasiswa = $mahasiswa->jurusan()->first();
            $krsMahasiswa = $mahasiswa->krs()->first();
            $krsMatkulDipilih = $krsMahasiswa->krsMatkul()->get();
            // return $this->debug_log([
            //     'mahasiswa' => $mahasiswa,
            //     'jurusanMahasiswa' => $jurusanMahasiswa,
            //     'krsMahasiswa' => $krsMahasiswa,
            //     'krsMatkulDipilih' => $krsMatkulDipilih,
            // ]);
            $setKRSData = self::setKRSData($jurusanMahasiswa, $krsMahasiswa, $krsMatkulDipilih, $mahasiswa['mhs_id']);

            return $this->successfulResponseJSON([
                'mahasiswa' => [
                    'mhs_id' => $mahasiswa['mhs_id'],
                    'nim' => $mahasiswa['nim'],
                    'nama' => $mahasiswa['nm_mhs'],
                    'jurusan' => [
                        'jur_id' => $jurusanMahasiswa['jur_id'],
                        'nama_jurusan' => $jurusanMahasiswa['nama_jurusan'],
                        'nm_singkat' => $jurusanMahasiswa['nm_singkat'],
                    ],
                    'krs' => $setKRSData,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'fail',
                'message' => $e->getTrace(),
            ], 500);
        }
    }

    public function updateStatusKRSMahasiswa(Request $request)
    {
        try {
            // is user dosen wali?
            self::getStatusDosenWali($request);

            $request->validate([
                'mhs_id' => 'required',
                'krs_id' => 'required',
                'sts_krs' => 'required',
                'krs_matkul' => 'required|array',
                'krs_matkul.*.k_disetujui' => 'required|boolean',
                'krs_matkul.*.krs_mk_id' => 'required',
            ]);

            // cek mahasiswa dan krs
            $currentKRS = KRS::where('krs_id', $request->krs_id)->first();
            $mahasiswa = Mahasiswa::where('mhs_id', $request->mhs_id)->first();

            if (! $currentKRS or ! $mahasiswa) {
                return $this->failedResponseJSON('Nilai mhs_id atau krs_id tidak ditemukan', 404);
            }

            $krsData = [
                'sts_krs' => $request->sts_krs,
            ];
            $krsMatkul = $request->krs_matkul;

            // cek setiap krs_mk_id
            $tempKrsMatkulArr = [];
            foreach ($request->krs_matkul as $item) {
                $krsMkMatch = KRSMatkul::where('krs_id', $request->krs_id)
                    ->where('krs_mk_id', $item['krs_mk_id'])
                    ->first();

                if (! $krsMkMatch) {
                    return $this->failedResponseJSON('Nilai krs_mk_id '.$item['krs_mk_id'].' tidak sesuai', 400);
                }

                array_push($tempKrsMatkulArr, $item['krs_mk_id']);
            }

            // krs ditolak
            if ($request->sts_krs === 'D') {
                $request->validate([
                    'ditolak_alasan' => 'required',
                ]);
                $ditolakStlhSah = $currentKRS['sts_krs'] === 'S' ?? false;
                $krsData['ditolak_alasan'] = $request->ditolak_alasan;
                $krsData['ditolak_tanggal'] = now();
                $krsData['ditolak_stlh_sah'] = $ditolakStlhSah;
            }

            // update ke table krs
            DB::beginTransaction();
            $updateKRS = KRS::where('krs_id', $request->krs_id)
                ->update($krsData);

            // update setiap mk di table krs_mk
            if ($updateKRS) {
                foreach ($krsMatkul as $matkul) {
                    $updateKRSMatkul = KRSMatkul::where('krs_id', $request->krs_id)
                        ->where('krs_mk_id', $matkul['krs_mk_id'])
                        ->update([
                            'k_disetujui' => $matkul['k_disetujui'],
                        ]);

                    if (! $updateKRSMatkul) {
                        DB::rollBack();

                        return $this->failedResponseJSON('Matakuliah di KRS Mahasiswa gagal diperbarui', 500);
                    }
                }

                /**
                 * ! Terdapat fungsi dari db yang belum diketahui
                 * jadi saat status berubah menjadi 'D' atau ditolak,
                 * maka otomatis krs_id_last di table mahasiswa
                 * langsung kembali ke KRS sebelumnya (dalam artian fungsi yang belum diketahui ini
                 * menganggap bahwa KRS ditolak menandakan bahwa data KRSnya dihapus
                 * ).
                 *
                 * Sehingga untuk mempertahankan KRS mahasiswa saat ini (agar nmr_krs tidak berubah)
                 * kolom krs_id_last harus diupdate lagi.
                 */
                $updateKRSIdLast = Mahasiswa::where('mhs_id', $request->mhs_id)
                    ->update([
                        'krs_id_last' => $request->krs_id,
                    ]);

                if ($updateKRSIdLast) {
                    DB::commit();

                    return $this->successfulResponseJSON([
                        'krs_id' => $request->krs_id,
                    ], 'KRS mahasiswa berhasil diperbaharui');
                }
            }

            DB::rollBack();

            return $this->failedResponseJSON('KRS Mahasiswa gagal diperbarui', 500);
        } catch (\Exception $e) {
            DB::rollBack();

            return ErrorHandler::handle($e);
        }
    }

    public function getListKRSMahasiswa(Request $request)
    {
        try {
            $page = $request->query('page') ?? null;
            $search = $request->query('search') ?? null;
            $tahunMasuk = $request->query('tahun_masuk') ?? null;
            $semester = $request->query('semester') ?? null;
            $listMahasiswa = Dosen::getListKRSMahasiswa($this->user['dosen_id'], $search, $tahunMasuk);

            // 27-08-2024
            // ambil mahasiswa berdasarkan tahun ajaran aktif
            $listMahasiswaId = collect($listMahasiswa)->pluck('mhs_id')->toArray();
            $listTahunAjaranAktif = TahunAjaranView::select('tahun_id')
                ->get()
                ->pluck('tahun_id')
                ->toArray();
            $listMhsIdTersediaKRS = KRS::whereIn('mhs_id', $listMahasiswaId)
                ->whereIn('tahun_id', $listTahunAjaranAktif)
                ->get()
                ->pluck('mhs_id')
                ->toArray();
            $listMahasiswa = array_values(collect($listMahasiswa)->whereIn('mhs_id', $listMhsIdTersediaKRS)->toArray());

            // ambil krs yang statusnya P dan S saja
            $listMahasiswa = array_values(collect($listMahasiswa)->filter(function ($item) {
                if (count($item['krs']) > 0) {
                    return $item;
                }
            })->toArray());

            // jika ada filter semester pada query params
            if ($semester) {
                $listMahasiswa = array_values(collect($listMahasiswa)->filter(function ($item) use ($semester) {
                    return $item['krs'][0]['semester'] == $semester;
                })->toArray());
            }

            // jika ada filter page pada query params
            if ($page and ! $search) {
                $perPage = 10;
                $currentPage = (int) $page ?? Paginator::resolveCurrentPage();
                $currentPageData = Collection::make($listMahasiswa)->slice(($currentPage - 1) * $perPage, $perPage);
                $paginator = new Paginator($currentPageData->all(), $perPage, $currentPage);
                $paginatedData = array_values($paginator->items());
                $totalNextItems = count($listMahasiswa) - ($currentPage == 1
                    ? $currentPageData->count()
                    : $currentPageData->count() + ($perPage * $currentPage)
                );

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'list_krs_mahasiswa' => $paginatedData,
                    ],
                    'meta' => [
                        'current_page' => $currentPage,
                        'total_items' => count($listMahasiswa),
                        'items_per_page' => $paginator->perPage(),
                        'prev_page_url' => $currentPage == 1 ? null
                            : config('app.url').'krs/mahasiswa/list'.substr($paginator->previousPageUrl(), 1),
                        'next_page_url' => ($totalNextItems > -1 and count($listMahasiswa) > 10)
                            ? config('app.url').'krs/mahasiswa/list?page='.$currentPage + 1
                            : null,
                    ],
                ], 200);
            }

            return $this->successfulResponseJSON([
                'list_krs_mahasiswa' => $listMahasiswa,
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function exportKRSMahasiswa(Request $request)
    {
        $filters = $request->validate([
            'sts_krs' => 'required|in:P,D,S',
            'jns_mhs' => 'nullable|in:R,K,E',
            'sts_mhs' => 'nullable|in:A,C,N',
            'masuk_tahun' => 'nullable|integer',
            'semester' => 'nullable|integer|min:1|max:8',
            'format' => 'nullable|in:xlsx,data',
        ]);

        $activeTahunIds = TahunAjaranView::pluck('tahun_id');
        $students = collect(Dosen::getListKRSMahasiswa($this->user['dosen_id']))
            ->when($filters['jns_mhs'] ?? null, fn ($rows, $value) => $rows->where('jns_mhs', $value))
            ->when($filters['sts_mhs'] ?? null, fn ($rows, $value) => $rows->where('sts_mhs', $value))
            ->when($filters['masuk_tahun'] ?? null, fn ($rows, $value) => $rows->where('masuk_tahun', $value));

        $rows = $students->flatMap(function ($student) use ($activeTahunIds, $filters) {
            return collect($student['krs'])->whereIn('tahun_id', $activeTahunIds)->where('sts_krs', $filters['sts_krs'])
                ->when($filters['semester'] ?? null, fn ($rows, $value) => $rows->where('semester', $value))
                ->map(fn ($krs) => array_merge($student->toArray(), ['krs_item' => $krs]));
        })->values();

        $status = ['P' => 'Pengajuan', 'D' => 'Draft-Ditolak', 'S' => 'Disetujui'][$filters['sts_krs']];
        $dosenWali = collect($this->user)->only(['nama_dan_gelar', 'nama', 'kd_dosen', 'nidn'])->filter()->all();
        if (($filters['format'] ?? 'xlsx') === 'data') {
            return $this->successfulResponseJSON(['rows' => $rows, 'filters' => $filters, 'status_label' => $status, 'dosen_wali' => $dosenWali]);
        }

        $labels = ['R' => 'Reguler', 'K' => 'Karyawan', 'E' => 'Eksekutif', 'A' => 'Aktif', 'C' => 'Cuti', 'N' => 'Tidak Aktif'];
        $export = [
            ['REKAP KRS DOSEN WALI - '.strtoupper($status)],
            ['Dosen Wali', $dosenWali['nama_dan_gelar'] ?? $dosenWali['nama'] ?? '-', $dosenWali['kd_dosen'] ?? $dosenWali['nidn'] ?? ''],
            ['Filter', collect($filters)->except(['format', 'sts_krs'])->filter(fn ($value) => $value !== null)->map(fn ($value, $key) => "$key: $value")->implode(', ') ?: 'Semua'],
            ['Dibuat', now()->format('d-m-Y H:i:s')],
            [],
            ['No', 'NIM', 'Nama Mahasiswa', 'Jenis Mahasiswa', 'Status Mahasiswa', 'Tahun Angkatan', 'Semester', 'Nomor KRS', 'Tanggal KRS', 'Status KRS'],
        ];
        foreach ($rows as $index => $row) {
            $krs = $row['krs_item'];
            $export[] = [$index + 1, $row['nim'], $row['nm_mhs'], $labels[$row['jns_mhs']] ?? $row['jns_mhs'], $labels[$row['sts_mhs']] ?? $row['sts_mhs'], $row['masuk_tahun'], $krs['semester'], $krs['nmr_krs'], $krs['tanggal'], $status];
        }

        $suffix = collect($filters)->except(['format'])->filter(fn ($value) => $value !== null)->map(fn ($value, $key) => "$key-$value")->implode('-');

        return Excel::download(new RekapExport($export), preg_replace('/[^A-Za-z0-9_-]/', '-', "Rekap-KRS-$status-$suffix").'.xlsx');
    }

    public function getListFilterAngkatan()
    {
        try {
            $listMahasiswa = MahasiswaView::where('dosen_id', $this->user['dosen_id'])
                ->select('angkatan')
                ->distinct('angkatan')
                ->orderBy('angkatan', 'DESC')
                ->get();

            return $this->successfulResponseJSON([
                'filter_angkatan' => $listMahasiswa,
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getListFilterSemester()
    {
        try {
            $listMahasiswaId = MahasiswaView::where('dosen_id', $this->user['dosen_id'])
                ->where('sts_mhs', 'A')
                ->select('mhs_id')
                ->get()
                ->pluck('mhs_id')
                ->toArray();
            $listTahunAjaran = TahunAjaranView::select('tahun_id')->get()->pluck('tahun_id')->toArray();
            $listFilterSemesterTersedia = KRS::whereIn('mhs_id', $listMahasiswaId)
                ->whereIn('tahun_id', $listTahunAjaran)
                ->select('semester')
                ->distinct('semester')
                ->get();

            return $this->successfulResponseJSON([
                'filter_semester' => $listFilterSemesterTersedia,
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getListMahasiswaKHS(Request $request)
    {
        try {
            $page = max((int) $request->query('page', 1), 1);
            $perPage = min(max((int) $request->query('per_page', 10), 5), 50);
            $search = $request->query('search');
            $status = $request->query('status');
            $angkatan = $request->query('angkatan');
            $jnsMhs = $request->query('jns_mhs');
            $khsFilter = $request->query('khs');
            $semesterFilter = $request->query('semesters', 'all');

            $query = MahasiswaView::where('dosen_id', $this->user['dosen_id']);

            if (in_array($status, ['active', 'A'], true)) {
                $query->where('sts_mhs', 'A');
            } elseif (in_array($status, ['inactive', 'nonactive', 'nonaktif'], true)) {
                $query->where('sts_mhs', '!=', 'A');
            } elseif (filled($status) && $status !== 'all') {
                $query->where('sts_mhs', $status);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('nim', 'like', '%'.$search.'%')
                        ->orWhere('nm_mhs', 'like', '%'.$search.'%');
                });
            }

            if ($angkatan) {
                $query->where('angkatan', $angkatan);
            }

            if ($jnsMhs) {
                $query->where('jns_mhs', $jnsMhs);
            }

            $semesterFilterList = [];
            if ($semesterFilter && $semesterFilter !== 'all') {
                $semesterFilterList = collect(explode(',', $semesterFilter))
                    ->map(fn ($semester) => (int) trim($semester))
                    ->filter(fn ($semester) => $semester >= 1)
                    ->unique()
                    ->values()
                    ->toArray();

                if (count($semesterFilterList) > 0) {
                    $candidateMhsIds = (clone $query)->pluck('mhs_id')->toArray();

                    if (count($candidateMhsIds) < 1) {
                        $query->whereRaw('1 = 0');
                    } else {
                        $mhsIdsWithSemester = NilaiAkhirView::whereIn('mhs_id', $candidateMhsIds)
                            ->whereHas('matakuliah', function ($matakuliahQuery) use ($semesterFilterList) {
                                $matakuliahQuery->whereIn('semester', $semesterFilterList);
                            })
                            ->select('mhs_id')
                            ->distinct()
                            ->pluck('mhs_id')
                            ->toArray();

                        count($mhsIdsWithSemester) > 0
                            ? $query->whereIn('mhs_id', $mhsIdsWithSemester)
                            : $query->whereRaw('1 = 0');
                    }
                }
            }

            $filteredByKhs = in_array($khsFilter, ['ada', '1', 'available', 'tidak_ada', '0', 'empty'], true);

            if ($filteredByKhs) {
                $mahasiswaList = $query->orderBy('nim', 'asc')->get();
            } else {
                $total = (clone $query)->count();
                $mahasiswaList = $query->orderBy('nim', 'asc')
                    ->skip(($page - 1) * $perPage)
                    ->take($perPage)
                    ->get();
            }

            $mahasiswaWithKHS = $mahasiswaList->map(function ($mhs) {
                $nilaiList = NilaiAkhirView::getNilaiAkhirByMhsId($mhs->mhs_id);

                $hasKhs = $nilaiList->count() > 0;
                $totalSks = 0;
                $ipk = 0;
                $semesterTersedia = [];

                if ($hasKhs) {
                    $totalMutu = $nilaiList->sum('mutu');
                    $countTotal = $nilaiList->count();

                    $totalSks = $nilaiList->sum(function ($nilai) {
                        return $nilai->matakuliah->sks ?? 0;
                    });

                    $ipk = $countTotal > 0 ? round($totalMutu / $countTotal, 2) : 0;

                    $semesterTersedia = $nilaiList->pluck('matakuliah.semester')
                        ->filter()
                        ->unique()
                        ->sort()
                        ->values()
                        ->toArray();
                }

                return [
                    'mhs_id' => $mhs->mhs_id,
                    'nim' => $mhs->nim,
                    'nama' => $mhs->nm_mhs,
                    'nm_mhs' => $mhs->nm_mhs,
                    'angkatan' => $mhs->angkatan,
                    'masuk_tahun' => $mhs->angkatan,
                    'status_mahasiswa' => $mhs->sts_mhs,
                    'sts_mhs' => $mhs->sts_mhs,
                    'status_mahasiswa_label' => $this->getStatusMahasiswaLabel($mhs->sts_mhs),
                    'jenis_mahasiswa' => $mhs->jns_mhs,
                    'jns_mhs' => $mhs->jns_mhs,
                    'jenis_mahasiswa_label' => $this->getJenisMahasiswaLabel($mhs->jns_mhs),
                    'has_khs' => $hasKhs,
                    'total_sks' => $totalSks,
                    'ipk' => $ipk,
                    'semester_tersedia' => $semesterTersedia,
                    'semesters_available' => $semesterTersedia,
                ];
            });

            if ($filteredByKhs && in_array($khsFilter, ['ada', '1', 'available'], true)) {
                $mahasiswaWithKHS = $mahasiswaWithKHS->filter(fn ($m) => $m['has_khs']);
            } elseif ($filteredByKhs && in_array($khsFilter, ['tidak_ada', '0', 'empty'], true)) {
                $mahasiswaWithKHS = $mahasiswaWithKHS->filter(fn ($m) => ! $m['has_khs']);
            }

            if ($filteredByKhs) {
                $total = $mahasiswaWithKHS->count();
                $mahasiswaWithKHS = $mahasiswaWithKHS->forPage($page, $perPage)->values();
            }

            $filterAngkatan = MahasiswaView::where('dosen_id', $this->user['dosen_id'])
                ->select('angkatan')
                ->distinct()
                ->orderBy('angkatan', 'desc')
                ->pluck('angkatan');

            $filterJenisMhsRaw = MahasiswaView::where('dosen_id', $this->user['dosen_id'])
                ->select('jns_mhs')
                ->distinct()
                ->pluck('jns_mhs');

            $filterJenisMhs = $filterJenisMhsRaw->map(function ($jns) {
                return [
                    'label' => $this->getJenisMahasiswaLabel($jns),
                    'value' => $jns,
                ];
            })->values();

            $filterSemesterMhsIds = MahasiswaView::where('dosen_id', $this->user['dosen_id'])
                ->pluck('mhs_id');

            $filterSemesters = NilaiAkhirView::whereIn('mhs_id', $filterSemesterMhsIds)
                ->with(['matakuliah' => function ($matakuliahQuery) {
                    $matakuliahQuery->select('mk_id', 'semester');
                }])
                ->get()
                ->pluck('matakuliah.semester')
                ->filter()
                ->unique()
                ->sort()
                ->values();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'mahasiswa' => $mahasiswaWithKHS,
                    'meta' => [
                        'current_page' => (int) $page,
                        'per_page' => (int) $perPage,
                        'total_items' => $total,
                        'total' => $total,
                        'total_pages' => (int) ceil($total / $perPage),
                        'last_page' => (int) ceil($total / $perPage),
                    ],
                    'filters' => [
                        'status' => [
                            ['label' => 'Aktif', 'value' => 'active'],
                            ['label' => 'Nonaktif', 'value' => 'inactive'],
                        ],
                        'angkatan' => $filterAngkatan,
                        'jenis' => $filterJenisMhs,
                        'jenis_mahasiswa' => $filterJenisMhs,
                        'semesters' => $filterSemesters,
                    ],
                ],
                'meta' => [
                    'current_page' => (int) $page,
                    'per_page' => (int) $perPage,
                    'total_items' => $total,
                    'total_pages' => ceil($total / $perPage),
                ],
            ], 200);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getKHSMahasiswa(Request $request, $mhsId)
    {
        try {
            $this->assertDosenWaliMahasiswa($mhsId);

            $semesterFilter = $request->query('semesters', 'all');

            $mahasiswa = MahasiswaView::where('mhs_id', $mhsId)->first();

            if (! $mahasiswa) {
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Mahasiswa tidak ditemukan',
                ], 404);
            }

            $khsData = $this->buildKHSDataForMahasiswa($mhsId, $semesterFilter);

            if (empty($khsData['semesters'])) {
                return response()->json([
                    'status' => 'success',
                    'data' => null,
                    'message' => $khsData['message'] ?? 'Mahasiswa belum memiliki data KHS.',
                ], 200);
            }

            $dosenWali = $this->user->nama_dan_gelar
                ?? $this->user->nama
                ?? $this->user->nm_dosen
                ?? null;

            $responseData = [
                'mahasiswa' => [
                    'mhs_id' => $mahasiswa->mhs_id,
                    'nim' => $mahasiswa->nim,
                    'nama' => $mahasiswa->nm_mhs,
                    'angkatan' => $mahasiswa->angkatan,
                    'prodi' => trim($mahasiswa->nama_jurusan ?? ''),
                    'dosen_wali' => $dosenWali,
                    'status_mahasiswa' => $mahasiswa->sts_mhs,
                    'jenis_mahasiswa' => $mahasiswa->jns_mhs,
                ],
                'summary' => $khsData['summary'],
                'semesters' => $khsData['semesters'],
            ];

            if (isset($khsData['message'])) {
                $responseData['message'] = $khsData['message'];
            }

            return response()->json([
                'status' => 'success',
                'data' => $responseData,
            ], 200);
        } catch (HttpException $e) {
            return response()->json([
                'status' => 'fail',
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function setKRSData($jurusan, $krs, $krsMatkul, $mhsId)
    {
        $tempMatkul = [];

        foreach ($krsMatkul as $index => $item) {
            $detailMatkul = MatkulDiselenggarakanView::where('jur_id', $jurusan['jur_id'])
                ->where('tahun_id', $krs['tahun_id'])
                ->where('mk_id', $item['mk_id'])
                ->first();

            // return $this->debug_log([
            //     'detailMatkul' => $detailMatkul,
            //     'item' => $item,
            // ]);
            if (! $detailMatkul) {
                continue;
            }

            // get nilai akhir
            $nilaiAkhirMatkul = NilaiAkhirView::where('mhs_id', $mhsId)
                ->where('mk_id', $item['mk_id'])
                ->select('nilai', 'mutu')
                ->first();

            $tempMatkul[] = [
                'krs_mk_id' => $item['krs_mk_id'],
                'mk_id' => $item['mk_id'],
                'sts_mk_krs' => $item['sts_mk_krs'],
                'tgl_perubahan' => $item['tgl_perubahan'],
                'kd_mk' => $detailMatkul['kd_mk'],
                'nm_mk' => $detailMatkul['nm_mk'],
                'sks' => $detailMatkul['sks'],
                'k_disetujui' => $item['k_disetujui'],
                'nilai_akhir' => $nilaiAkhirMatkul,
            ];
        }

        $krsData = [
            'krs_id' => $krs['krs_id'],
            'tahun_id' => $krs['tahun_id'],
            'nmr_krs' => $krs['nmr_krs'],
            'tanggal' => $krs['tanggal'],
            'semester' => $krs['semester'],
            'sts_krs' => $krs['sts_krs'],
            'kd_kampus' => $krs['kd_kampus'],
            'kd_chanel' => $krs['kd_chanel'],
            'pengajuan_catatan' => $krs['pengajuan_catatan'],
            'ditolak_tanggal' => $krs['ditolak_tanggal'],
            'ditolak_alasan' => $krs['ditolak_alasan'],
            'ditolak_stlh_sah' => $krs['ditolak_stlh_sah'],
            'krs_matkul' => collect($tempMatkul)->values(),
        ];

        return $krsData;
    }

    private function getStatusDosenWali($request)
    {
        $isDosenWali = $this->isDosenWali($this->user, $request->query('mhs_id'));

        if (! $isDosenWali) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Bukan wali dosen dari mahasiswa',
            ], 403);
        }
    }

    private function assertDosenWaliMahasiswa($mhsId)
    {
        $isDosenWali = $this->isDosenWali($this->user, $mhsId);

        if (! $isDosenWali) {
            throw new HttpException(403, 'Anda bukan dosen wali dari mahasiswa ini');
        }

        return true;
    }

    private function getStatusMahasiswaLabel($sts_mhs)
    {
        $labels = [
            'A' => 'Aktif',
            'C' => 'Cuti',
            'L' => 'Lulus',
            'N' => 'Tidak Aktif',
        ];

        return $labels[$sts_mhs] ?? $sts_mhs;
    }

    private function getJenisMahasiswaLabel($jns_mhs)
    {
        $labels = [
            'R' => 'Reguler',
            'K' => 'Karyawan',
            'E' => 'Ekstensi',
        ];

        return $labels[$jns_mhs] ?? $jns_mhs;
    }

    private function buildKHSDataForMahasiswa($mhsId, $semesterFilter)
    {
        $nilaiList = NilaiAkhirView::getNilaiAkhirByMhsId($mhsId);

        if ($nilaiList->count() === 0) {
            return [
                'summary' => null,
                'semesters' => [],
                'message' => 'Mahasiswa belum memiliki data KHS.',
            ];
        }

        $semestersToInclude = [];
        if ($semesterFilter === 'all') {
            $semestersToInclude = $nilaiList->pluck('matakuliah.semester')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->toArray();
        } else {
            $semestersToInclude = array_map('intval', explode(',', $semesterFilter));
        }

        $filteredNilai = $nilaiList->filter(function ($nilai) use ($semestersToInclude) {
            return in_array($nilai->matakuliah->semester ?? 0, $semestersToInclude);
        });

        $totalMutu = $filteredNilai->sum('mutu');
        $countTotal = $filteredNilai->count();
        $totalSks = $filteredNilai->sum(function ($nilai) {
            return $nilai->matakuliah->sks ?? 0;
        });
        $ipk = $countTotal > 0 ? round($totalMutu / $countTotal, 2) : 0;

        $countNilaiAll = $filteredNilai->countBy('nilai');

        $groupedBySemester = $filteredNilai->groupBy(function ($nilai) {
            return $nilai->matakuliah->semester ?? 0;
        });

        $semestersData = [];
        foreach ($groupedBySemester as $semester => $nilaiSemester) {
            $countItem = $nilaiSemester->count();
            $mutuSemester = $nilaiSemester->sum('mutu');
            $sksSemester = $nilaiSemester->sum(function ($nilai) {
                return $nilai->matakuliah->sks ?? 0;
            });
            $ipSemester = $countItem > 0 ? round($mutuSemester / $countItem, 2) : 0;

            $countNilai = $nilaiSemester->countBy('nilai');

            $matakuliahData = [];
            foreach ($nilaiSemester as $nilai) {
                $matakuliahData[] = [
                    'mk_id' => $nilai->mk_id,
                    'kd_mk' => trim($nilai->matakuliah->kd_mk ?? ''),
                    'nm_mk' => trim($nilai->matakuliah->nm_mk ?? ''),
                    'sks' => $nilai->matakuliah->sks ?? 0,
                    'nilai' => $nilai->nilai,
                    'mutu' => $nilai->mutu,
                ];
            }

            $semestersData[] = [
                'semester' => (int) $semester,
                'total_sks' => $sksSemester,
                'total_ip' => $ipSemester,
                'total_matakuliah' => $countItem,
                'total_nilai_a' => $countNilai['A'] ?? 0,
                'total_nilai_b' => $countNilai['B'] ?? 0,
                'total_nilai_c' => $countNilai['C'] ?? 0,
                'total_nilai_d' => $countNilai['D'] ?? 0,
                'total_nilai_e' => $countNilai['E'] ?? 0,
                'matakuliah' => $matakuliahData,
            ];
        }

        usort($semestersData, function ($a, $b) {
            return $a['semester'] <=> $b['semester'];
        });

        return [
            'summary' => [
                'total_sks' => $totalSks,
                'total_semua_ip' => $ipk,
                'total_nilai_a' => $countNilaiAll['A'] ?? 0,
                'total_nilai_b' => $countNilaiAll['B'] ?? 0,
                'total_nilai_c' => $countNilaiAll['C'] ?? 0,
                'total_nilai_d' => $countNilaiAll['D'] ?? 0,
                'total_nilai_e' => $countNilaiAll['E'] ?? 0,
            ],
            'semesters' => $semestersData,
        ];
    }
}
