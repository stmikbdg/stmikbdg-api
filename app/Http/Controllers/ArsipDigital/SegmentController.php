<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\Segment;
use App\Models\ArsipDigital\SegmentMember;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class SegmentController extends Controller
{
    public function index(Request $request, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $query = Segment::query()->withCount('members');

            if ($request->filled('target_role')) {
                $query->where('target_role', $request->input('target_role'));
            }

            return $this->successfulResponseJSON(['segments' => $query->orderBy('name')->get()->toArray()]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(Request $request, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'target_role' => ['required', 'in:mahasiswa,dosen'],
                'source' => ['sometimes', 'in:manual,import,campus_api'],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            $segment = Segment::create($payload + [
                'source' => 'manual',
                'is_active' => true,
                'created_by_user_id' => auth()->user()->id,
            ]);

            return $this->successfulResponseJSON(['segment' => $segment->toArray()], 'Segment berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, int $segment_id, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $segment = Segment::with('members')->findOrFail($segment_id);

            return $this->successfulResponseJSON(['segment' => $segment->toArray()]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function update(Request $request, int $segment_id, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'target_role' => ['sometimes', 'in:mahasiswa,dosen'],
                'source' => ['sometimes', 'in:manual,import,campus_api'],
                'is_active' => ['sometimes', 'boolean'],
            ]);
            $segment = Segment::findOrFail($segment_id);
            $segment->fill($payload);
            $segment->save();

            return $this->successfulResponseJSON(['segment' => $segment->toArray()], 'Segment berhasil diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function destroy(Request $request, int $segment_id, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            Segment::findOrFail($segment_id)->delete();

            return $this->successfulResponseJSONV2('Segment berhasil dihapus.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function addMember(Request $request, int $segment_id, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $segment = Segment::findOrFail($segment_id);
            $payload = $this->memberPayload($request);
            if (isset($payload['target_role']) && $payload['target_role'] !== $segment->target_role) {
                return $this->failedResponseJSON('Role member harus sama dengan role segment.', 422);
            }

            $member = SegmentMember::create($payload + [
                'segment_id' => $segment->segment_id,
                'target_role' => $segment->target_role,
            ]);

            return $this->successfulResponseJSON(['segment_member' => $member->toArray()], 'Member segment berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function deleteMember(Request $request, int $segment_id, int $segment_member_id, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            SegmentMember::where('segment_id', $segment_id)->findOrFail($segment_member_id)->delete();

            return $this->successfulResponseJSONV2('Member segment berhasil dihapus.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function importMembers(Request $request, int $segment_id, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $segment = Segment::findOrFail($segment_id);
            $payload = $request->validate([
                'items' => ['required', 'array', 'min:1'],
                'items.*.target_user_id' => ['nullable', 'integer'],
                'items.*.identifier' => ['required', 'string', 'max:100'],
                'items.*.name_snapshot' => ['nullable', 'string', 'max:255'],
                'items.*.angkatan_snapshot' => ['nullable', 'string', 'max:20'],
                'items.*.prodi_snapshot' => ['nullable', 'string', 'max:255'],
                'items.*.status_snapshot' => ['nullable', 'string', 'max:255'],
                'items.*.metadata' => ['nullable', 'array'],
            ]);

            $members = [];
            foreach ($payload['items'] as $item) {
                $members[] = SegmentMember::create($item + [
                    'segment_id' => $segment->segment_id,
                    'target_role' => $segment->target_role,
                ])->toArray();
            }

            return $this->successfulResponseJSON(['segment_members' => $members], 'Import member segment berhasil.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function memberPayload(Request $request): array
    {
        return $request->validate([
            'target_user_id' => ['nullable', 'integer'],
            'target_role' => ['sometimes', 'in:mahasiswa,dosen'],
            'identifier' => ['required', 'string', 'max:100'],
            'name_snapshot' => ['nullable', 'string', 'max:255'],
            'angkatan_snapshot' => ['nullable', 'string', 'max:20'],
            'prodi_snapshot' => ['nullable', 'string', 'max:255'],
            'status_snapshot' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);
    }
}
