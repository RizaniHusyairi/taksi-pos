import 'dart:math' as math;
import 'package:flutter/material.dart';

/// Latar layar pembuka: bandara malam dengan satu taksi Angkasa Jaya menunggu.
///
/// Lukisan dasarnya satu gambar (`assets/images/splash_bg.jpg`, dibuat lewat
/// Higgsfield), lalu dihidupkan di Flutter — bukan video. Alasannya praktis:
/// splash adalah detik paling sibuk saat aplikasi dibuka, dan memutar video di
/// sana berarti menambah paket `video_player`, aset megabyte-an, plus waktu
/// menyalakan dekoder tepat ketika pengguna paling menunggu. Gambar diam +
/// beberapa puluh operasi canvas memberi kesan yang sama dengan biaya nyaris nol.
///
/// Dua AnimationController, keduanya berumur sependek layar ini:
/// - [_ambient] berputar terus untuk kelipan bintang, beacon menara, dan lampu
///   landasan yang mengalir;
/// - [_intro] sekali jalan untuk gerakan kamera "mendarat" saat layar muncul.
///
/// Semua siklus objek HABIS MEMBAGI [_ambientSeconds] supaya tidak ada lompatan
/// saat putaran mengulang.
const int _ambientSeconds = 12;

/// Ukuran asli aset, untuk memetakan koordinat "fraksi gambar" ke layar
/// setelah [BoxFit.cover] memotong sisi kiri/kanan.
const double _bgWidth = 1080;
const double _bgHeight = 1935;

const String kSplashBackdropAsset = 'assets/images/splash_bg.jpg';

class SplashBackdrop extends StatefulWidget {
  final Widget child;
  const SplashBackdrop({super.key, required this.child});

  @override
  State<SplashBackdrop> createState() => _SplashBackdropState();
}

class _SplashBackdropState extends State<SplashBackdrop>
    with TickerProviderStateMixin {
  late final AnimationController _ambient = AnimationController(
    vsync: this,
    duration: const Duration(seconds: _ambientSeconds),
  )..repeat();

  late final AnimationController _intro = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1600),
  )..forward();

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    precacheImage(const AssetImage(kSplashBackdropAsset), context);
  }

  @override
  void dispose() {
    _ambient.dispose();
    _intro.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final reduceMotion = MediaQuery.maybeDisableAnimationsOf(context) ?? false;

    return Stack(
      children: [
        // Warna dasar = langit paling gelap, supaya tak ada kilatan putih
        // selama gambar masih didekode.
        const Positioned.fill(child: ColoredBox(color: Color(0xFF04173D))),
        Positioned.fill(
          child: RepaintBoundary(
            child: reduceMotion
                ? const _SplashScene(t: 0.2, intro: 1)
                : AnimatedBuilder(
                    animation: Listenable.merge([_ambient, _intro]),
                    builder: (context, _) => _SplashScene(
                      t: _ambient.value,
                      intro: Curves.easeOutCubic.transform(_intro.value),
                    ),
                  ),
          ),
        ),
        // Peredam lembut di tengah: teks putih layar pembuka berdiri di sana.
        const Positioned.fill(
          child: IgnorePointer(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  stops: [0.0, 0.42, 0.72, 1.0],
                  colors: [
                    Color(0x2604173D),
                    Color(0x5904173D),
                    Color(0x2604173D),
                    Color(0x3D04173D),
                  ],
                ),
              ),
            ),
          ),
        ),
        widget.child,
      ],
    );
  }
}

class _SplashScene extends StatelessWidget {
  /// Posisi ambient 0..1 (satu putaran = [_ambientSeconds] detik).
  final double t;

  /// Kemajuan gerakan masuk 0..1 (sekali jalan).
  final double intro;

  const _SplashScene({required this.t, required this.intro});

  @override
  Widget build(BuildContext context) {
    // Kamera "mendarat": mulai sedikit membesar lalu mengendap ke ukuran asli.
    final scale = 1.06 - 0.06 * intro;

    return Transform(
      alignment: Alignment.center,
      transform: Matrix4.identity()..scaleByDouble(scale, scale, 1.0, 1.0),
      child: Stack(
        fit: StackFit.expand,
        children: [
          Opacity(
            // Gambar ikut memudar masuk agar peralihan dari warna dasar mulus.
            opacity: (0.35 + 0.65 * intro).clamp(0.0, 1.0),
            child: const Image(
              image: AssetImage(kSplashBackdropAsset),
              fit: BoxFit.cover,
              alignment: Alignment.center,
              filterQuality: FilterQuality.medium,
            ),
          ),
          CustomPaint(painter: SplashOverlayPainter(t, intro)),
        ],
      ),
    );
  }
}

/// Lapisan animasi di atas gambar layar pembuka.
class SplashOverlayPainter extends CustomPainter {
  final double t;
  final double intro;

  const SplashOverlayPainter(this.t, this.intro);

  double get _seconds => t * _ambientSeconds;

  double _phase(double cycleSeconds, [double offset = 0]) =>
      ((_seconds / cycleSeconds) + offset) % 1.0;

  double _pulse(double cycleSeconds, [double offset = 0]) =>
      0.5 - 0.5 * math.cos(2 * math.pi * _phase(cycleSeconds, offset));

  @override
  void paint(Canvas canvas, Size size) {
    final s = math.max(size.width / _bgWidth, size.height / _bgHeight);
    final ox = (size.width - _bgWidth * s) / 2;
    final oy = (size.height - _bgHeight * s) / 2;
    Offset at(double fx, double fy) =>
        Offset(ox + fx * _bgWidth * s, oy + fy * _bgHeight * s);
    final u = _bgHeight * s;

    canvas.save();
    canvas.clipRect(Offset.zero & size);

    // Efek ikut memudar bersama gambar supaya tidak "mengambang" di awal.
    final a = intro.clamp(0.0, 1.0);

    _paintStars(canvas, at, u, a);
    _paintPlaneBlink(canvas, at, u, a);
    _paintTowerBeacon(canvas, at, u, a);
    _paintRunwayLights(canvas, at, u, a);
    _paintTerminalGlow(canvas, at, u, a);
    _paintHeadlights(canvas, at, u, a);

    canvas.restore();
  }

  /// Bintang berkelip di paruh atas.
  void _paintStars(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
    double a,
  ) {
    final rnd = math.Random(7); // seed tetap: bintang tidak boleh meloncat
    const cycles = [2.0, 3.0, 4.0, 6.0];
    final paint = Paint();

    for (var i = 0; i < 26; i++) {
      final fx = rnd.nextDouble();
      final fy = 0.02 + rnd.nextDouble() * 0.42;
      final r = (0.0006 + rnd.nextDouble() * 0.0009) * u;
      final glow = _pulse(cycles[i % cycles.length], rnd.nextDouble());
      paint.color = Colors.white.withValues(alpha: (0.15 + 0.6 * glow) * a);
      canvas.drawCircle(at(fx, fy), r * (0.7 + 0.6 * glow), paint);
    }
  }

  /// Lampu navigasi pesawat yang sedang menanjak.
  void _paintPlaneBlink(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
    double a,
  ) {
    // Kedip pendek sekali tiap 2 detik, seperti lampu antitabrakan sungguhan.
    if (_phase(2) > 0.14) return;
    canvas.drawCircle(
      at(0.685, 0.163),
      u * 0.0032,
      Paint()
        ..color = const Color(0xFFFF5A4E).withValues(alpha: 0.9 * a)
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.004),
    );
  }

  /// Beacon merah di puncak menara ATC.
  ///
  /// Koordinat diukur dari piksel merah pada aset (x 620–624, y 1211–1215 pada
  /// 1080×1935). Ukur ulang bila gambarnya diganti.
  void _paintTowerBeacon(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
    double a,
  ) {
    final pos = at(0.5759, 0.6269);
    final glow = _pulse(3);

    canvas.drawCircle(
      pos,
      u * (0.004 + 0.007 * glow),
      Paint()
        ..color = const Color(0xFFFF4438).withValues(alpha: (0.25 + 0.5 * glow) * a)
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.007),
    );
    canvas.drawCircle(
      pos,
      u * 0.002,
      Paint()..color = const Color(0xFFFFD2CB).withValues(alpha: (0.5 + 0.5 * glow) * a),
    );
  }

  /// Lampu tepi landasan yang mengalir — isyarat paling khas bandara.
  void _paintRunwayLights(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
    double a,
  ) {
    final paint = Paint()
      ..blendMode = BlendMode.plus
      ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.003);

    // Dua garis landasan pada aset; masing-masing dialiri 9 titik cahaya yang
    // bergerak ke kanan dengan kecepatan berbeda agar terasa punya kedalaman.
    void jalur(double fy, double cycle, double offset, double alpha) {
      final maju = _phase(cycle, offset);
      for (var i = 0; i < 9; i++) {
        final fx = ((i / 9) + maju) % 1.0;
        // Redup di tepi layar, terang di tengah — meniru perspektif.
        final tepi = math.sin(fx * math.pi);
        paint.color = const Color(0xFF7FE3FF)
            .withValues(alpha: (alpha * tepi * a).clamp(0.0, 1.0));
        canvas.drawCircle(at(fx, fy), u * 0.0035 * (0.6 + 0.4 * tepi), paint);
      }
    }

    jalur(0.807, 4, 0.0, 0.55);
    jalur(0.836, 6, 0.35, 0.38);
  }

  /// Denyut halus pada deretan jendela terminal.
  void _paintTerminalGlow(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
    double a,
  ) {
    final glow = _pulse(6);
    final rect = Rect.fromPoints(at(0.0, 0.716), at(0.62, 0.775));
    canvas.drawRect(
      rect,
      Paint()
        ..blendMode = BlendMode.plus
        ..color = const Color(0xFFFFB259).withValues(alpha: 0.05 * (0.4 + 0.6 * glow) * a)
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.012),
    );
  }

  /// Sorot lampu depan taksi + genangan cahaya di aspal.
  void _paintHeadlights(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
    double a,
  ) {
    final glow = 0.75 + 0.25 * _pulse(4);
    final lampu = Paint()
      ..blendMode = BlendMode.plus
      ..color = const Color(0xFFFFF0CC).withValues(alpha: 0.22 * glow * a)
      ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.010);

    for (final fx in [0.269, 0.459]) {
      canvas.drawCircle(at(fx, 0.848), u * 0.012, lampu);
    }

    // Pantulan memanjang di aspal basah tepat di bawah mobil.
    canvas.drawOval(
      Rect.fromCenter(
        center: at(0.364, 0.945),
        width: u * 0.13,
        height: u * 0.05,
      ),
      Paint()
        ..blendMode = BlendMode.plus
        ..color = const Color(0xFFFFE0A8).withValues(alpha: 0.10 * glow * a)
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.016),
    );
  }

  @override
  bool shouldRepaint(covariant SplashOverlayPainter old) =>
      old.t != t || old.intro != intro;
}
