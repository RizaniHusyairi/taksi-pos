import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:permission_handler/permission_handler.dart';

import '../theme/app_colors.dart';

/// Kamera langsung di dalam aplikasi untuk memotret bukti transfer penumpang.
///
/// Berbeda dengan kamera bawaan lewat image_picker, CSO tetap di aplikasi:
/// ada bingkai panduan, ketuk untuk fokus, lampu kilat, lalu tinjau hasil
/// (Ulangi / Gunakan) sebelum dipakai. Mengembalikan path JPEG atau null.
class LiveCameraScreen extends StatefulWidget {
  final String judul;
  final String petunjuk;

  const LiveCameraScreen({
    super.key,
    this.judul = 'Foto Bukti Transfer',
    this.petunjuk = 'Arahkan ke layar bukti transfer di HP penumpang',
  });

  static Future<String?> buka(
    BuildContext context, {
    String judul = 'Foto Bukti Transfer',
    String petunjuk = 'Arahkan ke layar bukti transfer di HP penumpang',
  }) {
    return Navigator.push<String>(
      context,
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => LiveCameraScreen(judul: judul, petunjuk: petunjuk),
      ),
    );
  }

  @override
  State<LiveCameraScreen> createState() => _LiveCameraScreenState();
}

class _LiveCameraScreenState extends State<LiveCameraScreen>
    with WidgetsBindingObserver {
  List<CameraDescription> _kamera = const [];
  int _indeks = 0;
  CameraController? _ctrl;
  String? _galat;
  bool _izinDitolak = false;
  bool _memotret = false;
  FlashMode _flash = FlashMode.off;
  Offset? _titikFokus;
  XFile? _hasil;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _mulai();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _ctrl?.dispose();
    super.dispose();
  }

  /// Kamera dilepas saat aplikasi ke latar dan dibuka lagi saat kembali —
  /// sesuai anjuran plugin camera, supaya tidak "terkunci" oleh aplikasi lain.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final c = _ctrl;
    if (c == null || !c.value.isInitialized) return;
    if (state == AppLifecycleState.inactive) {
      _ctrl = null;
      c.dispose();
      if (mounted) setState(() {});
    } else if (state == AppLifecycleState.resumed && _hasil == null) {
      _pasang(_kamera[_indeks]);
    }
  }

  Future<void> _mulai() async {
    setState(() {
      _galat = null;
      _izinDitolak = false;
    });
    try {
      _kamera = await availableCameras();
      if (_kamera.isEmpty) {
        setState(() => _galat = 'Kamera tidak ditemukan di perangkat ini.');
        return;
      }
      final belakang = _kamera.indexWhere(
        (k) => k.lensDirection == CameraLensDirection.back,
      );
      _indeks = belakang < 0 ? 0 : belakang;
      await _pasang(_kamera[_indeks]);
    } on CameraException catch (e) {
      _tanganiGalat(e);
    }
  }

  Future<void> _pasang(CameraDescription kamera) async {
    final lama = _ctrl;
    _ctrl = null;
    await lama?.dispose();

    final c = CameraController(
      kamera,
      // 1080p cukup tajam untuk nominal & nama di bukti transfer, dan tetap
      // jauh di bawah batas unggah 5 MB.
      ResolutionPreset.veryHigh,
      enableAudio: false,
      imageFormatGroup: ImageFormatGroup.jpeg,
    );
    try {
      await c.initialize();
      await c.setFlashMode(_flash);
      if (!mounted) return c.dispose();
      setState(() => _ctrl = c);
    } on CameraException catch (e) {
      await c.dispose();
      _tanganiGalat(e);
    }
  }

  void _tanganiGalat(CameraException e) {
    if (!mounted) return;
    final ditolak =
        e.code.contains('AccessDenied') ||
        e.code.contains('Permission') ||
        e.code.contains('permission');
    setState(() {
      _izinDitolak = ditolak;
      _galat = ditolak
          ? 'Izin kamera ditolak. Izinkan kamera agar bisa memotret bukti transfer.'
          : 'Kamera tidak bisa dibuka (${e.description ?? e.code}).';
    });
  }

  Future<void> _gantiFlash() async {
    final c = _ctrl;
    if (c == null) return;
    final berikut = _flash == FlashMode.off ? FlashMode.torch : FlashMode.off;
    try {
      await c.setFlashMode(berikut);
      setState(() => _flash = berikut);
    } on CameraException catch (_) {}
  }

  Future<void> _gantiKamera() async {
    if (_kamera.length < 2) return;
    _indeks = (_indeks + 1) % _kamera.length;
    _flash = FlashMode.off;
    await _pasang(_kamera[_indeks]);
  }

  Future<void> _fokus(TapDownDetails d, BoxConstraints batas) async {
    final c = _ctrl;
    if (c == null || !c.value.isInitialized) return;
    final titik = Offset(
      d.localPosition.dx / batas.maxWidth,
      d.localPosition.dy / batas.maxHeight,
    );
    setState(() => _titikFokus = d.localPosition);
    try {
      await c.setFocusPoint(titik);
      await c.setExposurePoint(titik);
    } on CameraException catch (_) {
      // Sebagian kamera tidak mendukung fokus manual — abaikan saja.
    }
    Future.delayed(const Duration(milliseconds: 900), () {
      if (mounted) setState(() => _titikFokus = null);
    });
  }

  Future<void> _potret() async {
    final c = _ctrl;
    if (c == null || !c.value.isInitialized || _memotret) return;
    setState(() => _memotret = true);
    try {
      final foto = await c.takePicture();
      if (_flash == FlashMode.torch) await c.setFlashMode(FlashMode.off);
      await c.pausePreview();
      if (mounted) setState(() => _hasil = foto);
    } on CameraException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Gagal memotret: ${e.description ?? e.code}')),
        );
      }
    } finally {
      if (mounted) setState(() => _memotret = false);
    }
  }

  Future<void> _ulangi() async {
    final lama = _hasil;
    setState(() => _hasil = null);
    try {
      if (lama != null) File(lama.path).deleteSync();
    } catch (_) {}
    try {
      await _ctrl?.resumePreview();
      if (_flash == FlashMode.torch) await _ctrl?.setFlashMode(FlashMode.torch);
    } on CameraException catch (_) {
      await _pasang(_kamera[_indeks]);
    }
  }

  // === Build ===============================================================

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        fit: StackFit.expand,
        children: [
          if (_hasil != null)
            Image.file(File(_hasil!.path), fit: BoxFit.contain)
          else if (_ctrl != null && _ctrl!.value.isInitialized)
            _pratinjau()
          else if (_galat != null)
            _layarGalat()
          else
            const Center(child: CircularProgressIndicator(color: Colors.white)),
          _bilahAtas(),
          Positioned(left: 0, right: 0, bottom: 0, child: _bilahBawah()),
        ],
      ),
    );
  }

  Widget _pratinjau() {
    final c = _ctrl!;
    return LayoutBuilder(
      builder: (context, batas) {
        // Pratinjau memenuhi layar (cover) tanpa distorsi.
        final rasio =
            c.value.aspectRatio; // lebar/tinggi dalam orientasi sensor
        final layar = batas.maxWidth / batas.maxHeight;
        final skala = 1 / (rasio * layar);
        return GestureDetector(
          onTapDown: (d) => _fokus(d, batas),
          child: Stack(
            fit: StackFit.expand,
            children: [
              ClipRect(
                child: Transform.scale(
                  scale: skala < 1 ? 1 / skala : skala,
                  child: Center(child: CameraPreview(c)),
                ),
              ),
              IgnorePointer(child: CustomPaint(painter: _BingkaiPanduan())),
              if (_titikFokus != null)
                Positioned(
                  left: _titikFokus!.dx - 34,
                  top: _titikFokus!.dy - 34,
                  child: TweenAnimationBuilder<double>(
                    key: ValueKey(_titikFokus),
                    tween: Tween(begin: 1.4, end: 1),
                    duration: const Duration(milliseconds: 250),
                    builder: (_, v, child) =>
                        Transform.scale(scale: v, child: child),
                    child: Container(
                      width: 68,
                      height: 68,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        border: Border.all(color: AppColors.gold, width: 2),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }

  Widget _bilahAtas() {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(8, 4, 8, 0),
        child: Column(
          children: [
            Row(
              children: [
                _tombolBulat(
                  Icons.close_rounded,
                  () => Navigator.pop(context),
                  tooltip: 'Tutup',
                ),
                Expanded(
                  child: Text(
                    _hasil == null ? widget.judul : 'Periksa Foto',
                    textAlign: TextAlign.center,
                    style: GoogleFonts.outfit(
                      color: Colors.white,
                      fontSize: 17,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                if (_hasil == null && _ctrl != null)
                  _tombolBulat(
                    _flash == FlashMode.torch
                        ? Icons.flash_on_rounded
                        : Icons.flash_off_rounded,
                    _gantiFlash,
                    aktif: _flash == FlashMode.torch,
                    tooltip: 'Lampu',
                  )
                else
                  const SizedBox(width: 44),
              ],
            ),
            const SizedBox(height: 10),
            if (_galat == null)
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 14,
                  vertical: 7,
                ),
                decoration: BoxDecoration(
                  color: Colors.black.withValues(alpha: 0.5),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  _hasil == null
                      ? widget.petunjuk
                      : 'Pastikan nominal & nama penerima terbaca jelas',
                  textAlign: TextAlign.center,
                  style: GoogleFonts.outfit(
                    color: Colors.white,
                    fontSize: 12.5,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _bilahBawah() {
    return Container(
      padding: const EdgeInsets.fromLTRB(24, 20, 24, 28),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Colors.transparent, Colors.black.withValues(alpha: 0.75)],
        ),
      ),
      child: SafeArea(
        top: false,
        child: _hasil != null ? _aksiTinjau() : _aksiPotret(),
      ),
    );
  }

  Widget _aksiPotret() {
    final siap = _ctrl != null && _ctrl!.value.isInitialized;
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        const SizedBox(width: 52),
        GestureDetector(
          onTap: siap ? _potret : null,
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 120),
            width: 78,
            height: 78,
            padding: EdgeInsets.all(_memotret ? 10 : 5),
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              border: Border.all(color: Colors.white, width: 4),
            ),
            child: Container(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: siap ? Colors.white : Colors.white30,
              ),
            ),
          ),
        ),
        _kamera.length > 1 && siap
            ? _tombolBulat(
                Icons.cameraswitch_rounded,
                _gantiKamera,
                tooltip: 'Ganti kamera',
              )
            : const SizedBox(width: 52),
      ],
    );
  }

  Widget _aksiTinjau() {
    return Row(
      children: [
        Expanded(
          child: OutlinedButton.icon(
            onPressed: _ulangi,
            icon: const Icon(Icons.refresh_rounded),
            label: const Text('Ulangi'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Colors.white,
              side: const BorderSide(color: Colors.white54),
              padding: const EdgeInsets.symmetric(vertical: 15),
              textStyle: GoogleFonts.outfit(fontWeight: FontWeight.w700),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(16),
              ),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: FilledButton.icon(
            onPressed: () => Navigator.pop(context, _hasil!.path),
            icon: const Icon(Icons.check_rounded),
            label: const Text('Gunakan Foto'),
            style: FilledButton.styleFrom(
              backgroundColor: AppColors.success,
              padding: const EdgeInsets.symmetric(vertical: 15),
              textStyle: GoogleFonts.outfit(fontWeight: FontWeight.w700),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(16),
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _layarGalat() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(
              Icons.no_photography_rounded,
              color: Colors.white54,
              size: 56,
            ),
            const SizedBox(height: 14),
            Text(
              _galat!,
              textAlign: TextAlign.center,
              style: GoogleFonts.outfit(color: Colors.white, fontSize: 14.5),
            ),
            const SizedBox(height: 18),
            FilledButton(
              onPressed: _izinDitolak ? openAppSettings : _mulai,
              child: Text(_izinDitolak ? 'Buka Pengaturan' : 'Coba Lagi'),
            ),
            if (_izinDitolak)
              TextButton(
                onPressed: _mulai,
                child: const Text(
                  'Coba Lagi',
                  style: TextStyle(color: Colors.white70),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _tombolBulat(
    IconData icon,
    VoidCallback onTap, {
    bool aktif = false,
    String? tooltip,
  }) {
    return Tooltip(
      message: tooltip ?? '',
      child: Material(
        color: aktif ? AppColors.gold : Colors.black.withValues(alpha: 0.45),
        shape: const CircleBorder(),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Icon(
              icon,
              color: aktif ? Colors.black87 : Colors.white,
              size: 22,
            ),
          ),
        ),
      ),
    );
  }
}

/// Bingkai panduan potret (rasio layar ponsel) dengan sudut tebal, supaya CSO
/// memotret bukti transfer utuh dan lurus.
class _BingkaiPanduan extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width * 0.78;
    final h = (w * 1.6).clamp(0.0, size.height * 0.62);
    final r = Rect.fromCenter(
      center: size.center(const Offset(0, -10)),
      width: w,
      height: h,
    );
    final rr = RRect.fromRectAndRadius(r, const Radius.circular(18));

    canvas.drawPath(
      Path.combine(
        PathOperation.difference,
        Path()..addRect(Offset.zero & size),
        Path()..addRRect(rr),
      ),
      Paint()..color = Colors.black.withValues(alpha: 0.35),
    );
    canvas.drawRRect(
      rr,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1
        ..color = Colors.white38,
    );

    final sudut = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 4
      ..strokeCap = StrokeCap.round
      ..color = Colors.white;
    const p = 28.0;
    for (final (dx, dy) in [(0.0, 0.0), (1.0, 0.0), (0.0, 1.0), (1.0, 1.0)]) {
      final x = r.left + r.width * dx;
      final y = r.top + r.height * dy;
      final sx = dx == 0 ? 1 : -1;
      final sy = dy == 0 ? 1 : -1;
      canvas.drawLine(Offset(x, y + sy * 6), Offset(x, y + sy * p), sudut);
      canvas.drawLine(Offset(x + sx * 6, y), Offset(x + sx * p, y), sudut);
    }
  }

  @override
  bool shouldRepaint(_BingkaiPanduan old) => false;
}
