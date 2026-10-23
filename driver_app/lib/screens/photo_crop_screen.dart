import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image/image.dart' as img;

import '../theme/app_colors.dart';
import '../widgets/gradient_button.dart';

/// Layar pengatur potongan foto profil: geser & cubit (atau slider) untuk
/// memilih bagian foto di dalam lingkaran, putar 90°, lalu simpan.
///
/// Mengembalikan JPEG persegi [ukuranHasil] px, atau null bila dibatalkan.
/// Pemotongan dilakukan dengan memotret area kotak itu sendiri
/// (RepaintBoundary) — yang tersimpan persis yang terlihat pengguna.
class PhotoCropScreen extends StatefulWidget {
  final Uint8List bytes;

  const PhotoCropScreen({super.key, required this.bytes});

  static const ukuranHasil = 640;

  static Future<Uint8List?> buka(BuildContext context, Uint8List bytes) {
    return Navigator.push<Uint8List>(
      context,
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => PhotoCropScreen(bytes: bytes),
      ),
    );
  }

  @override
  State<PhotoCropScreen> createState() => _PhotoCropScreenState();
}

class _PhotoCropScreenState extends State<PhotoCropScreen> {
  static const _skalaMaks = 4.0;
  static const _latar = Color(0xFF0B1220);

  final _tc = TransformationController();
  final _batas = GlobalKey();

  Size? _ukuranGambar;
  int _putar = 0; // kelipatan 90°
  double _sisi = 0; // sisi kotak potong (logical px)
  double _skala = 1;
  bool _menggeser = false;
  bool _menyimpan = false;
  bool _perluReset = true;

  @override
  void initState() {
    super.initState();
    _tc.addListener(() {
      final s = _tc.value.getMaxScaleOnAxis();
      if ((s - _skala).abs() > 0.001) setState(() => _skala = s);
    });
    decodeImageFromList(widget.bytes).then((g) {
      if (!mounted) return;
      setState(
        () => _ukuranGambar = Size(g.width.toDouble(), g.height.toDouble()),
      );
      g.dispose();
    });
  }

  @override
  void dispose() {
    _tc.dispose();
    super.dispose();
  }

  /// Ukuran gambar di dalam kotak pada skala 1: menutup penuh kotak (cover),
  /// jadi tidak pernah ada area kosong di hasil potongan.
  Size get _ukuranAnak {
    final g = _ukuranGambar!;
    final w = _putar.isOdd ? g.height : g.width;
    final h = _putar.isOdd ? g.width : g.height;
    final k = _sisi / math.min(w, h);
    return Size(w * k, h * k);
  }

  void _reset() {
    final a = _ukuranAnak;
    _tc.value = Matrix4.identity()
      ..setTranslationRaw(-(a.width - _sisi) / 2, -(a.height - _sisi) / 2, 0);
  }

  /// Zoom dari slider, berpusat di tengah kotak, dengan batas geser yang
  /// sama seperti InteractiveViewer (gambar tetap menutup kotak).
  void _aturSkala(double v) {
    final m = _tc.value;
    final s = m.getMaxScaleOnAxis();
    final t = m.getTranslation();
    final c = _sisi / 2;
    final px = (c - t.x) / s;
    final py = (c - t.y) / s;
    final a = _ukuranAnak;
    final nx = (c - v * px).clamp(_sisi - a.width * v, 0.0);
    final ny = (c - v * py).clamp(_sisi - a.height * v, 0.0);
    _tc.value = Matrix4.diagonal3Values(v, v, 1)..setTranslationRaw(nx, ny, 0);
  }

  void _putarGambar() {
    setState(() => _putar = (_putar + 1) % 4);
    WidgetsBinding.instance.addPostFrameCallback((_) => _reset());
  }

  Future<void> _simpan() async {
    setState(() => _menyimpan = true);
    try {
      final boundary =
          _batas.currentContext!.findRenderObject() as RenderRepaintBoundary;
      final gambar = await boundary.toImage(
        pixelRatio: PhotoCropScreen.ukuranHasil / _sisi,
      );
      final png = await gambar.toByteData(format: ui.ImageByteFormat.png);
      gambar.dispose();
      final jpeg = await compute(_keJpeg, png!.buffer.asUint8List());
      if (mounted) Navigator.pop(context, jpeg);
    } catch (_) {
      if (mounted) {
        setState(() => _menyimpan = false);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Gagal memproses foto. Coba lagi.')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: _latar,
      body: SafeArea(
        child: Column(
          children: [
            _bilahAtas(),
            Expanded(
              child: _ukuranGambar == null
                  ? const Center(
                      child: CircularProgressIndicator(color: Colors.white),
                    )
                  : LayoutBuilder(
                      builder: (context, c) {
                        final sisi = math
                            .min(c.maxWidth - 48, c.maxHeight - 24)
                            .clamp(160.0, 360.0);
                        if (sisi != _sisi) {
                          _sisi = sisi;
                          _perluReset = true;
                        }
                        if (_perluReset) {
                          _perluReset = false;
                          WidgetsBinding.instance.addPostFrameCallback(
                            (_) => _reset(),
                          );
                        }
                        return Center(child: _areaPotong());
                      },
                    ),
            ),
            _kontrol(),
          ],
        ),
      ),
    );
  }

  Widget _bilahAtas() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 8, 16, 4),
      child: Row(
        children: [
          IconButton(
            onPressed: _menyimpan ? null : () => Navigator.pop(context),
            icon: const Icon(Icons.close_rounded, color: Colors.white),
            tooltip: 'Batal',
          ),
          const SizedBox(width: 4),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Atur Foto Profil',
                  style: GoogleFonts.outfit(
                    color: Colors.white,
                    fontSize: 18,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                Text(
                  'Geser & cubit untuk mengatur posisi',
                  style: GoogleFonts.outfit(
                    color: Colors.white.withValues(alpha: 0.6),
                    fontSize: 12.5,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _areaPotong() {
    final a = _ukuranAnak;
    return SizedBox(
      width: _sisi,
      height: _sisi,
      child: Stack(
        children: [
          RepaintBoundary(
            key: _batas,
            child: ClipRect(
              child: SizedBox(
                width: _sisi,
                height: _sisi,
                child: InteractiveViewer(
                  transformationController: _tc,
                  constrained: false,
                  minScale: 1,
                  maxScale: _skalaMaks,
                  boundaryMargin: EdgeInsets.zero,
                  onInteractionStart: (_) => setState(() => _menggeser = true),
                  onInteractionEnd: (_) => setState(() => _menggeser = false),
                  child: SizedBox(
                    width: a.width,
                    height: a.height,
                    child: RotatedBox(
                      quarterTurns: _putar,
                      child: Image.memory(
                        widget.bytes,
                        fit: BoxFit.fill,
                        gaplessPlayback: true,
                        filterQuality: FilterQuality.medium,
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
          IgnorePointer(
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 180),
              child: CustomPaint(
                key: ValueKey(_menggeser),
                size: Size(_sisi, _sisi),
                painter: _MaskLingkaran(grid: _menggeser),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _kontrol() {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 20),
      decoration: const BoxDecoration(
        color: Color(0xFF131C2E),
        borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              const Icon(Icons.zoom_out_rounded, color: Colors.white54),
              Expanded(
                child: SliderTheme(
                  data: SliderTheme.of(context).copyWith(
                    activeTrackColor: AppColors.lightCyan,
                    inactiveTrackColor: Colors.white24,
                    thumbColor: Colors.white,
                    overlayColor: AppColors.lightCyan.withValues(alpha: 0.2),
                    trackHeight: 4,
                  ),
                  child: Slider(
                    value: _skala.clamp(1.0, _skalaMaks),
                    min: 1,
                    max: _skalaMaks,
                    onChanged: _ukuranGambar == null || _menyimpan
                        ? null
                        : _aturSkala,
                  ),
                ),
              ),
              const Icon(Icons.zoom_in_rounded, color: Colors.white54),
              const SizedBox(width: 8),
              SizedBox(
                width: 42,
                child: Text(
                  '${_skala.toStringAsFixed(1)}×',
                  textAlign: TextAlign.right,
                  style: GoogleFonts.outfit(
                    color: Colors.white,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: _tombolAlat(
                  Icons.rotate_90_degrees_ccw_rounded,
                  'Putar',
                  _ukuranGambar == null || _menyimpan ? null : _putarGambar,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _tombolAlat(
                  Icons.center_focus_strong_rounded,
                  'Reset',
                  _ukuranGambar == null || _menyimpan ? null : _reset,
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          GradientButton(
            label: 'GUNAKAN FOTO',
            icon: Icons.check_rounded,
            loading: _menyimpan,
            onPressed: _ukuranGambar == null || _menyimpan ? null : _simpan,
          ),
        ],
      ),
    );
  }

  Widget _tombolAlat(IconData icon, String label, VoidCallback? onTap) {
    return Material(
      color: Colors.white.withValues(alpha: 0.08),
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 12),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(icon, color: Colors.white, size: 20),
              const SizedBox(width: 8),
              Text(
                label,
                style: GoogleFonts.outfit(
                  color: Colors.white,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Gelapkan area di luar lingkaran, beri cincin putih, dan tampilkan garis
/// sepertiga saat sedang digeser supaya wajah mudah diposisikan di tengah.
class _MaskLingkaran extends CustomPainter {
  final bool grid;
  _MaskLingkaran({required this.grid});

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final lingkaran = Path()..addOval(rect.deflate(2));
    final luar = Path.combine(
      PathOperation.difference,
      Path()..addRect(rect),
      lingkaran,
    );
    canvas.drawPath(
      luar,
      Paint()..color = Colors.black.withValues(alpha: 0.55),
    );

    if (grid) {
      final p = Paint()
        ..color = Colors.white.withValues(alpha: 0.35)
        ..strokeWidth = 1;
      canvas.save();
      canvas.clipPath(lingkaran);
      for (final f in [1 / 3, 2 / 3]) {
        canvas.drawLine(
          Offset(size.width * f, 0),
          Offset(size.width * f, size.height),
          p,
        );
        canvas.drawLine(
          Offset(0, size.height * f),
          Offset(size.width, size.height * f),
          p,
        );
      }
      canvas.restore();
    }

    canvas.drawPath(
      lingkaran,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2.5
        ..color = Colors.white,
    );
  }

  @override
  bool shouldRepaint(_MaskLingkaran old) => old.grid != grid;
}

/// PNG hasil tangkapan → JPEG (jauh lebih kecil untuk diunggah). Dijalankan
/// di isolate lewat compute() supaya UI tidak tersendat.
Uint8List _keJpeg(Uint8List png) {
  final g = img.decodePng(png)!;
  return Uint8List.fromList(img.encodeJpg(g, quality: 88));
}
