<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

/**
 * Modification V7 (item 6): files chosen on the registration form are not lost when the form comes back with
 * errors. A browser cannot refill a file input, so each file that passed its own rules is kept on the private
 * disk (register-tmp/) and referenced only from the visitor's session. The next submit uses it when no new file
 * is chosen; the visitor can remove it. Files left behind are purged by `register:purge-tmp`.
 */
class RegisterUploadStash
{
    /** form field => final folder on the private disk */
    public const FIELDS = ['photo' => 'employee-photos', 'id_proof' => 'id-proofs'];

    public const DIR = 'register-tmp';

    private const KEY = 'register_uploads';

    /** The kept file for a field: [path, name, size, mime], or null. */
    public function get(string $field): ?array
    {
        $s = session(self::KEY.'.'.$field);
        if (! is_array($s) || ! isset(self::FIELDS[$field]) || ! str_starts_with($s['path'] ?? '', self::DIR.'/')
            || ! Storage::disk('private')->exists($s['path'])) {
            return null;
        }

        return $s;
    }

    /** The kept file is still wanted: it exists and the form did not say it was removed. */
    public function wanted(Request $request, string $field): bool
    {
        return $this->get($field) !== null && $request->input('keep_'.$field) === '1';
    }

    /** After a failed validation: keep each valid new file, drop removed or replaced ones. */
    public function keep(Request $request, MessageBag $errors): void
    {
        foreach (array_keys(self::FIELDS) as $field) {
            $file = $request->file($field);
            if ($file instanceof UploadedFile && $file->isValid()) {
                $this->forget($field); // a new file always replaces the kept one
                if (! $errors->has($field)) {
                    $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension());
                    $path = $file->storeAs(self::DIR, Str::random(40).'.'.$ext, 'private');
                    session([self::KEY.'.'.$field => [
                        'path' => $path,
                        'name' => mb_substr($file->getClientOriginalName(), 0, 255),
                        'size' => $file->getSize(),
                        'mime' => $file->getMimeType(),
                    ]]);
                }
            } elseif (! $this->wanted($request, $field)) {
                $this->forget($field);
            }
        }
    }

    /**
     * Moves the kept file to its final folder (used by a successful registration).
     *
     * @return array{0: string, 1: string}|null [final path, original name]
     */
    public function claim(string $field): ?array
    {
        $s = $this->get($field);
        if (! $s) {
            return null;
        }
        $final = self::FIELDS[$field].'/'.Str::random(40).'.'.pathinfo($s['path'], PATHINFO_EXTENSION);
        Storage::disk('private')->move($s['path'], $final);
        session()->forget(self::KEY.'.'.$field);

        return [$final, $s['name']];
    }

    public function forget(string $field): void
    {
        if ($s = $this->get($field)) {
            Storage::disk('private')->delete($s['path']);
        }
        session()->forget(self::KEY.'.'.$field);
    }

    public function clear(): void
    {
        foreach (array_keys(self::FIELDS) as $field) {
            $this->forget($field);
        }
    }

    /** Deletes kept files older than $hours (abandoned forms). Returns how many were removed. */
    public function purge(int $hours = 24): int
    {
        $disk = Storage::disk('private');
        $limit = now()->subHours($hours)->getTimestamp();
        $n = 0;
        foreach ($disk->files(self::DIR) as $path) {
            if ($disk->lastModified($path) < $limit) {
                $disk->delete($path);
                $n++;
            }
        }

        return $n;
    }
}
