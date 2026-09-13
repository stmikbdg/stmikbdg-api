<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\AuditLog;
use App\Models\ArsipDigital\InstitutionalUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InstitutionalUnitService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return InstitutionalUnit::query()
            ->when($filters['with_deleted'] ?? false, fn ($query) => $query->withTrashed())
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(fn ($query) => $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%'])
                    ->orWhereRaw('lower(code) like ?', ['%'.mb_strtolower($search).'%']));
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25);
    }

    public function create(array $payload, object $actor): InstitutionalUnit
    {
        return $this->transaction(function () use ($payload, $actor): InstitutionalUnit {
            $name = trim($payload['name']);
            $code = $this->nullableTrim($payload['code'] ?? null);
            $this->assertUnique($name, $code);
            $unit = InstitutionalUnit::create([
                ...$payload, 'name' => $name, 'code' => $code, 'is_active' => true, 'created_by_user_id' => $actor->id,
            ]);
            $this->audit('institutional_unit.created', $unit, $actor, ['name' => $unit->name, 'code' => $unit->code]);

            return $unit;
        });
    }

    public function update(int $unitId, array $payload, object $actor): InstitutionalUnit
    {
        return $this->transaction(function () use ($unitId, $payload, $actor): InstitutionalUnit {
            $unit = InstitutionalUnit::where('unit_id', $unitId)->lockForUpdate()->firstOrFail();
            $name = trim($payload['name'] ?? $unit->name);
            $code = array_key_exists('code', $payload) ? $this->nullableTrim($payload['code']) : $unit->code;
            $this->assertUnique($name, $code, $unitId);
            $before = $unit->only(['name', 'code', 'description']);
            $unit->fill(['name' => $name, 'code' => $code, 'description' => array_key_exists('description', $payload) ? $payload['description'] : $unit->description, 'updated_by_user_id' => $actor->id])->save();
            $this->audit('institutional_unit.updated', $unit, $actor, ['before' => $before, 'after' => $unit->only(array_keys($before))]);

            return $unit;
        });
    }

    public function deactivate(int $unitId, object $actor): void
    {
        $this->transaction(function () use ($unitId, $actor): void {
            $unit = InstitutionalUnit::where('unit_id', $unitId)->lockForUpdate()->firstOrFail();
            if ($unit->archives()->withTrashed()->exists()) {
                throw new HttpException(422, 'Unit masih direferensikan arsip lembaga.');
            }
            $unit->fill(['is_active' => false, 'updated_by_user_id' => $actor->id])->save();
            $unit->delete();
            $this->audit('institutional_unit.deactivated', $unit, $actor, ['name' => $unit->name, 'code' => $unit->code]);
        });
    }

    public function restore(int $unitId, object $actor): InstitutionalUnit
    {
        return $this->transaction(function () use ($unitId, $actor): InstitutionalUnit {
            $unit = InstitutionalUnit::withTrashed()->where('unit_id', $unitId)->lockForUpdate()->firstOrFail();
            $this->assertUnique($unit->name, $unit->code, $unitId);
            $unit->restore();
            $unit->fill(['is_active' => true, 'updated_by_user_id' => $actor->id])->save();
            $this->audit('institutional_unit.restored', $unit, $actor, ['name' => $unit->name, 'code' => $unit->code]);

            return $unit;
        });
    }

    private function transaction(callable $callback): mixed
    {
        try {
            return DB::connection(config('myconfig.database.first_connection'))->transaction($callback, 3);
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23505'], true)) {
                throw new HttpException(422, 'Nama atau kode unit aktif sudah digunakan.', $e);
            }
            throw $e;
        }
    }

    private function assertUnique(string $name, ?string $code, ?int $except = null): void
    {
        $query = InstitutionalUnit::where('is_active', true)->when($except, fn ($query) => $query->where('unit_id', '<>', $except));
        if ((clone $query)->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->exists()) {
            throw new HttpException(422, 'Nama unit aktif sudah digunakan.');
        }
        if ($code !== null && (clone $query)->whereRaw('lower(code) = ?', [mb_strtolower($this->nullableTrim($code))])->exists()) {
            throw new HttpException(422, 'Kode unit aktif sudah digunakan.');
        }
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function audit(string $action, InstitutionalUnit $unit, object $actor, array $metadata): void
    {
        AuditLog::create(['actor_user_id' => $actor->id, 'actor_role' => 'admin', 'action' => $action, 'entity_type' => 'institutional_unit', 'entity_id' => (string) $unit->unit_id, 'description' => 'Perubahan unit arsip lembaga.', 'metadata' => $metadata]);
    }
}
