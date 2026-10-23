import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../screens/photo_crop_screen.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import '../utils/api_error.dart';

/// Foto profil bulat untuk supir & CSO.
///
/// Bila [editable], mengetuknya membuka lembar Kamera / Galeri / Hapus; foto
/// yang dipilih dipotong di [PhotoCropScreen], langsung tampil (optimistis)
/// sambil diunggah, lalu profil disegarkan dari server.
class ProfileAvatar extends StatefulWidget {
  final String? photoPath;
  final String inisial;
  final double size;
  final bool editable;

  const ProfileAvatar({
    super.key,
    required this.photoPath,
    required this.inisial,
    this.size = 72,
    this.editable = true,
  });

  @override
  State<ProfileAvatar> createState() => _ProfileAvatarState();
}

class _ProfileAvatarState extends State<ProfileAvatar> {
  final _api = ApiService();
  Uint8List? _lokal; // pratinjau foto yang sedang diunggah
  bool _mengunggah = false;

  bool get _adaFoto =>
      _lokal != null || (widget.photoPath?.trim().isNotEmpty ?? false);

  void _toast(String pesan, {bool galat = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(pesan),
        backgroundColor: galat ? AppColors.danger : AppColors.success,
      ),
    );
  }

  Future<void> _bukaPilihan() async {
    final aksi = await showModalBottomSheet<String>(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (_) =>
          _LembarPilihan(pratinjau: _gambar(96), bisaHapus: _adaFoto),
    );
    if (!mounted || aksi == null) return;
    if (aksi == 'hapus') return _hapus();
    await _pilihDanUnggah(
      aksi == 'kamera' ? ImageSource.camera : ImageSource.gallery,
    );
  }

  Future<void> _pilihDanUnggah(ImageSource sumber) async {
    final XFile? file;
    try {
      file = await ImagePicker().pickImage(
        source: sumber,
        maxWidth: 1600,
        maxHeight: 1600,
        imageQuality: 92,
        preferredCameraDevice: CameraDevice.front,
      );
    } catch (_) {
      if (mounted) _toast('Tidak bisa membuka kamera/galeri.', galat: true);
      return;
    }
    if (file == null || !mounted) return;

    final bytes = await file.readAsBytes();
    if (!mounted) return;
    final hasil = await PhotoCropScreen.buka(context, bytes);
    if (hasil == null || !mounted) return;

    final auth = Provider.of<AuthProvider>(context, listen: false);
    setState(() {
      _lokal = hasil;
      _mengunggah = true;
    });
    try {
      await _api.uploadProfilePhoto(hasil, cso: auth.isCso);
      await auth.fetchProfile();
      if (mounted) _toast('Foto profil diperbarui.');
    } catch (e) {
      if (mounted) {
        setState(() => _lokal = null);
        _toast(apiErrorMessage(e), galat: true);
      }
    } finally {
      if (mounted) setState(() => _mengunggah = false);
    }
  }

  Future<void> _hapus() async {
    final yakin = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
        title: Text(
          'Hapus foto profil?',
          style: GoogleFonts.outfit(fontWeight: FontWeight.w800),
        ),
        content: Text(
          'Foto akan diganti inisial nama Anda.',
          style: GoogleFonts.outfit(color: AppColors.inkSoft),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Hapus'),
          ),
        ],
      ),
    );
    if (yakin != true || !mounted) return;

    final auth = Provider.of<AuthProvider>(context, listen: false);
    setState(() => _mengunggah = true);
    try {
      await _api.deleteProfilePhoto(cso: auth.isCso);
      await auth.fetchProfile();
      if (mounted) {
        setState(() => _lokal = null);
        _toast('Foto profil dihapus.');
      }
    } catch (e) {
      if (mounted) _toast(apiErrorMessage(e), galat: true);
    } finally {
      if (mounted) setState(() => _mengunggah = false);
    }
  }

  @override
  void didUpdateWidget(ProfileAvatar old) {
    super.didUpdateWidget(old);
    // Profil server sudah memuat foto baru → pratinjau lokal tidak dibutuhkan.
    if (old.photoPath != widget.photoPath && !_mengunggah) _lokal = null;
  }

  Widget _inisial(double size) => Container(
    width: size,
    height: size,
    alignment: Alignment.center,
    decoration: const BoxDecoration(
      shape: BoxShape.circle,
      gradient: LinearGradient(
        colors: [Colors.white, Color(0xFFD6EBFF)],
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
      ),
    ),
    child: Text(
      widget.inisial,
      style: GoogleFonts.outfit(
        color: AppColors.deepBlue,
        fontSize: size * 0.42,
        fontWeight: FontWeight.w800,
      ),
    ),
  );

  Widget _gambar(double size) {
    final Widget isi;
    if (_lokal != null) {
      isi = Image.memory(_lokal!, fit: BoxFit.cover, gaplessPlayback: true);
    } else if (widget.photoPath?.trim().isNotEmpty ?? false) {
      isi = Image.network(
        ApiService.storageUrl(widget.photoPath!),
        fit: BoxFit.cover,
        gaplessPlayback: true,
        errorBuilder: (_, _, _) => _inisial(size),
        loadingBuilder: (_, child, progres) =>
            progres == null ? child : Container(color: AppColors.paleBlue),
      );
    } else {
      return _inisial(size);
    }
    return ClipOval(
      child: SizedBox(width: size, height: size, child: isi),
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.size;
    final avatar = Container(
      width: s,
      height: s,
      padding: EdgeInsets.all(s * 0.04),
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: Colors.white,
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.18),
            blurRadius: 12,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Stack(
        fit: StackFit.expand,
        children: [
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 300),
            child: KeyedSubtree(
              key: ValueKey(_lokal?.hashCode ?? widget.photoPath),
              child: _gambar(s * 0.92),
            ),
          ),
          if (_mengunggah)
            ClipOval(
              child: Container(
                color: Colors.black.withValues(alpha: 0.4),
                alignment: Alignment.center,
                child: SizedBox(
                  width: s * 0.36,
                  height: s * 0.36,
                  child: const CircularProgressIndicator(
                    color: Colors.white,
                    strokeWidth: 3,
                  ),
                ),
              ),
            ),
        ],
      ),
    );

    if (!widget.editable) return avatar;

    final badge = s * 0.32;
    return Semantics(
      button: true,
      label: 'Ganti foto profil',
      child: GestureDetector(
        onTap: _mengunggah ? null : _bukaPilihan,
        child: SizedBox(
          width: s,
          height: s,
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              avatar,
              Positioned(
                right: -2,
                bottom: -2,
                child: Container(
                  width: badge,
                  height: badge,
                  decoration: BoxDecoration(
                    gradient: AppColors.buttonGradient,
                    shape: BoxShape.circle,
                    border: Border.all(color: Colors.white, width: 2),
                  ),
                  child: Icon(
                    Icons.photo_camera_rounded,
                    color: Colors.white,
                    size: badge * 0.52,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Lembar pilihan: pratinjau foto saat ini + Kamera / Galeri / Hapus.
class _LembarPilihan extends StatelessWidget {
  final Widget pratinjau;
  final bool bisaHapus;

  const _LembarPilihan({required this.pratinjau, required this.bisaHapus});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      padding: const EdgeInsets.fromLTRB(20, 10, 20, 20),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 44,
              height: 5,
              margin: const EdgeInsets.only(bottom: 18),
              decoration: BoxDecoration(
                color: AppColors.inkFaint.withValues(alpha: 0.4),
                borderRadius: BorderRadius.circular(10),
              ),
            ),
            pratinjau,
            const SizedBox(height: 12),
            Text(
              'Foto Profil',
              style: GoogleFonts.outfit(
                fontSize: 18,
                fontWeight: FontWeight.w800,
                color: AppColors.ink,
              ),
            ),
            Text(
              'Gunakan foto wajah yang jelas agar mudah dikenali.',
              textAlign: TextAlign.center,
              style: GoogleFonts.outfit(
                fontSize: 12.5,
                color: AppColors.inkSoft,
              ),
            ),
            const SizedBox(height: 18),
            Row(
              children: [
                Expanded(
                  child: _opsi(
                    context,
                    Icons.photo_camera_rounded,
                    'Kamera',
                    'kamera',
                    AppColors.skyBlue,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: _opsi(
                    context,
                    Icons.photo_library_rounded,
                    'Galeri',
                    'galeri',
                    const Color(0xFF7C4DFF),
                  ),
                ),
              ],
            ),
            if (bisaHapus) ...[
              const SizedBox(height: 10),
              TextButton.icon(
                onPressed: () => Navigator.pop(context, 'hapus'),
                icon: const Icon(
                  Icons.delete_outline_rounded,
                  color: AppColors.danger,
                ),
                label: Text(
                  'Hapus foto',
                  style: GoogleFonts.outfit(
                    color: AppColors.danger,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _opsi(
    BuildContext context,
    IconData icon,
    String label,
    String nilai,
    Color warna,
  ) {
    return Material(
      color: warna.withValues(alpha: 0.08),
      borderRadius: BorderRadius.circular(18),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: () => Navigator.pop(context, nilai),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 18),
          child: Column(
            children: [
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: warna, shape: BoxShape.circle),
                child: Icon(icon, color: Colors.white, size: 24),
              ),
              const SizedBox(height: 8),
              Text(
                label,
                style: GoogleFonts.outfit(
                  fontWeight: FontWeight.w700,
                  color: AppColors.ink,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
