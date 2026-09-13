<?php

namespace App\Services\ArsipDigital;

use App\Models\Users\Dosen;
use App\Models\Users\UserView;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OfficialDocumentSignerService
{
    public function directory(?string $search = null): array
    {
        $signers = UserView::query()
            ->where('is_prodi', true)
            ->limit(100)
            ->get(['id', 'kd_user'])
            ->map(fn ($user) => $this->snapshotFromUser($user));

        if ($search) {
            $needle = mb_strtolower(trim($search));
            $signers = $signers->filter(fn (array $signer) => str_contains(mb_strtolower($signer['name']), $needle)
                || str_contains(mb_strtolower($signer['identifier']), $needle));
        }

        return $signers
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function snapshot(int $userId, ?string $title = null): array
    {
        $user = UserView::query()
            ->where('id', $userId)
            ->where('is_prodi', true)
            ->first();

        if (! $user) {
            throw new HttpException(422, 'Pejabat penandatangan harus memiliki role prodi.');
        }

        $snapshot = $this->snapshotFromUser($user);
        $snapshot['title'] = trim((string) $title) ?: 'Ketua Program Studi';

        return $snapshot;
    }

    private function snapshotFromUser(object $user): array
    {
        $identifier = str_starts_with((string) $user->kd_user, 'DSN-')
            ? substr((string) $user->kd_user, 4)
            : null;
        $lecturer = $identifier
            ? Dosen::query()->where('kd_dosen', $identifier)->first()
            : null;
        $name = trim((string) ($lecturer->nm_dosen ?? $user->name ?? $user->kd_user));
        $degree = trim((string) ($lecturer->gelar ?? ''));

        return [
            'user_id' => (int) $user->id,
            'identifier' => (string) $user->kd_user,
            'name' => $degree !== '' && ! str_contains($name, $degree) ? $name.', '.$degree : $name,
            'title' => 'Ketua Program Studi',
        ];
    }
}
