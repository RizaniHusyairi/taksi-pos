<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Foto profil supir & CSO.
 *
 * Pemotongan (posisi & zoom) dilakukan pengguna di aplikasi; server hanya
 * menerima hasil persegi dan menyimpannya. Setiap unggahan memakai nama file
 * baru supaya cache gambar di ponsel tidak menampilkan foto lama.
 */
class ProfilePhotoController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ], [
            'photo.required' => 'Pilih foto terlebih dahulu.',
            'photo.image'    => 'File harus berupa gambar.',
            'photo.mimes'    => 'Format foto harus JPG, PNG, atau WEBP.',
            'photo.max'      => 'Ukuran foto maksimal 5 MB.',
        ]);

        $user = $request->user();
        $lama = $user->photo_path;

        $path = $request->file('photo')->store('avatars', 'public');
        $user->forceFill(['photo_path' => $path])->save();

        $this->hapusFile($lama);

        return response()->json([
            'message'    => 'Foto profil diperbarui.',
            'photo_path' => $path,
        ]);
    }

    public function destroy(Request $request)
    {
        $user = $request->user();
        $this->hapusFile($user->photo_path);
        $user->forceFill(['photo_path' => null])->save();

        return response()->json(['message' => 'Foto profil dihapus.']);
    }

    private function hapusFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
