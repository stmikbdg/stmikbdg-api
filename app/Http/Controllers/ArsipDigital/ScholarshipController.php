<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\ScholarshipType;
use App\Models\ArsipDigital\StudentScholarship;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class ScholarshipController extends Controller
{
    public function types(Request $request, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);

            return $this->successfulResponseJSON([
                'scholarship_types' => ScholarshipType::orderBy('name')->get()->toArray(),
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function storeType(Request $request, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'code' => ['nullable', 'string', 'max:100'],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'is_active' => ['sometimes', 'boolean'],
                'source' => ['sometimes', 'in:manual,campus_api,import'],
            ]);

            $type = ScholarshipType::create($payload + ['source' => 'manual', 'is_active' => true]);

            return $this->successfulResponseJSON(['scholarship_type' => $type->toArray()], 'Jenis beasiswa berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function updateType(Request $request, int $scholarship_type_id, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'code' => ['nullable', 'string', 'max:100'],
                'name' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'is_active' => ['sometimes', 'boolean'],
                'source' => ['sometimes', 'in:manual,campus_api,import'],
            ]);
            $type = ScholarshipType::findOrFail($scholarship_type_id);
            $type->fill($payload);
            $type->save();

            return $this->successfulResponseJSON(['scholarship_type' => $type->toArray()], 'Jenis beasiswa berhasil diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function deleteType(Request $request, int $scholarship_type_id, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            ScholarshipType::findOrFail($scholarship_type_id)->delete();

            return $this->successfulResponseJSONV2('Jenis beasiswa berhasil dihapus.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function studentScholarships(Request $request, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $query = StudentScholarship::query()->with('scholarshipType');

            foreach (['nim', 'student_user_id', 'scholarship_type_id', 'status', 'angkatan_snapshot'] as $field) {
                if ($request->filled($field)) {
                    $query->where($field, $request->input($field));
                }
            }

            return $this->successfulResponseJSON(['student_scholarships' => $query->orderByDesc('created_at')->get()->toArray()]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function storeStudentScholarship(Request $request, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $this->studentScholarshipPayload($request);
            $scholarship = StudentScholarship::create($payload + ['source' => 'manual']);

            return $this->successfulResponseJSON(['student_scholarship' => $scholarship->toArray()], 'Beasiswa mahasiswa berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function updateStudentScholarship(Request $request, int $student_scholarship_id, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $this->studentScholarshipPayload($request, true);
            $scholarship = StudentScholarship::findOrFail($student_scholarship_id);
            $scholarship->fill($payload);
            $scholarship->save();

            return $this->successfulResponseJSON(['student_scholarship' => $scholarship->toArray()], 'Beasiswa mahasiswa berhasil diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function importStudentScholarships(Request $request, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'items' => ['required', 'array', 'min:1'],
                'items.*.nim' => ['required', 'string', 'max:50'],
                'items.*.student_user_id' => ['nullable', 'integer'],
                'items.*.student_name_snapshot' => ['nullable', 'string', 'max:255'],
                'items.*.angkatan_snapshot' => ['nullable', 'string', 'max:20'],
                'items.*.scholarship_type_id' => ['required', 'integer'],
                'items.*.status' => ['sometimes', 'in:active,inactive,expired,unknown'],
                'items.*.period_label' => ['nullable', 'string', 'max:255'],
            ]);

            $created = [];
            foreach ($payload['items'] as $item) {
                $created[] = StudentScholarship::create($item + ['status' => 'active', 'source' => 'import'])->toArray();
            }

            return $this->successfulResponseJSON(['student_scholarships' => $created], 'Import beasiswa mahasiswa berhasil.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function studentScholarshipPayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'student_user_id' => ['nullable', 'integer'],
            'nim' => [$required, 'string', 'max:50'],
            'student_name_snapshot' => ['nullable', 'string', 'max:255'],
            'angkatan_snapshot' => ['nullable', 'string', 'max:20'],
            'scholarship_type_id' => [$required, 'integer'],
            'status' => ['sometimes', 'in:active,inactive,expired,unknown'],
            'period_label' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'source' => ['sometimes', 'in:manual,campus_api,import'],
            'metadata' => ['nullable', 'array'],
        ]);
    }
}
