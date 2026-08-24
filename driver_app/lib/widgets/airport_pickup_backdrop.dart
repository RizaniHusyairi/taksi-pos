import 'dart:math' as math;
import 'package:flutter/material.dart';
import '../theme/app_colors.dart';

/// Latar beranimasi khas **penjemputan bandara saat senja**: barisan taksi biru
/// menunggu di jalur kedatangan, terminal berlampu hangat, menara ATC, dan
/// pesawat lepas landas.
///
/// Lukisan dasarnya satu gambar (`assets/images/airport_pickup_bg.jpg`, dibuat
/// lewat Higgsfield), lalu "dihidupkan" di Flutter dengan lapisan ringan:
/// gerakan kamera Ken Burns yang sangat pelan, bintang berkelip, pesawat kedua
/// yang melintas, beacon menara, dan sapuan lampu taksi yang datang.
///
/// Hemat baterai: hanya SATU AnimationController, seluruh adegan berada di
/// dalam RepaintBoundary (kartu login tidak ikut repaint), dan tidak ada dekode
/// video — cuma satu gambar statis plus beberapa puluh operasi canvas.
///
/// Trik periode: controller berdurasi [_periodSeconds] detik, dan setiap objek
/// memakai siklus yang HABIS MEMBAGI angka itu (2, 3, 4, 5, 6, 10, 12, 15, 20,
/// 30, 60). Dengan begitu semua gerakan pas kembali ke posisi awal saat animasi
/// mengulang — tidak ada "lompatan" di detik terakhir.
const int _periodSeconds = 60;

/// Ukuran asli aset latar, dipakai untuk memetakan koordinat "fraksi gambar"
/// ke koordinat layar setelah [BoxFit.cover] memotong sisi kiri/kanan.
const double _bgWidth = 1080;
const double _bgHeight = 1935;

const String kAirportBackdropAsset = 'assets/images/airport_pickup_bg.jpg';

class AirportPickupBackdrop extends StatefulWidget {
  final Widget child;
  const AirportPickupBackdrop({super.key, required this.child});

  @override
  State<AirportPickupBackdrop> createState() => _AirportPickupBackdropState();
}

class _AirportPickupBackdropState extends State<AirportPickupBackdrop>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(seconds: _periodSeconds),
  )..repeat();

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Dekode gambar lebih awal supaya tidak ada kedipan saat layar tampil.
    precacheImage(const AssetImage(kAirportBackdropAsset), context);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Hormati "kurangi gerakan" di setelan aksesibilitas: tampilkan satu
    // bingkai diam, bukan animasi berjalan.
    final reduceMotion = MediaQuery.maybeDisableAnimationsOf(context) ?? false;

    return Stack(
      children: [
        // Warna dasar = langit paling gelap, supaya tidak ada kilatan putih
        // selama gambar masih didekode.
        const Positioned.fill(child: ColoredBox(color: Color(0xFF06265A))),
        Positioned.fill(
          child: RepaintBoundary(
            child: reduceMotion
                ? const _AirportScene(0.18, still: true)
                : AnimatedBuilder(
                    animation: _controller,
                    builder: (context, _) => _AirportScene(_controller.value),
                  ),
          ),
        ),
        // Peredup lembut agar teks & kartu login tetap terbaca di atas gambar.
        const Positioned.fill(
          child: IgnorePointer(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  stops: [0.0, 0.30, 0.62, 1.0],
                  colors: [
                    Color(0x4D06265A),
                    Color(0x2E06265A),
                    Color(0x3D072A5C),
                    Color(0x66041B3F),
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

/// Gambar latar + lapisan animasi, keduanya dikenai transformasi kamera yang
/// sama supaya efek tetap menempel pada objek di gambar.
class _AirportScene extends StatelessWidget {
  final double t;
  final bool still;
  const _AirportScene(this.t, {this.still = false});

  @override
  Widget build(BuildContext context) {
    // Ken Burns: satu gelombang sinus penuh per periode — mulai dan berakhir di
    // kecepatan nol, jadi pengulangannya tidak terasa.
    final k = still ? 0.5 : 0.5 - 0.5 * math.cos(2 * math.pi * t);
    final scale = 1.03 + 0.05 * k;
    final dy = (0.5 - k) * 10; // hanyut vertikal ±5 px

    return Transform(
      alignment: Alignment.center,
      transform: Matrix4.identity()
        ..scaleByDouble(scale, scale, 1.0, 1.0)
        ..translateByDouble(0.0, dy, 0.0, 1.0),
      child: Stack(
        fit: StackFit.expand,
        children: [
          const Image(
            image: AssetImage(kAirportBackdropAsset),
            fit: BoxFit.cover,
            alignment: Alignment.center,
            filterQuality: FilterQuality.medium,
          ),
          CustomPaint(painter: AirportPickupOverlayPainter(t)),
        ],
      ),
    );
  }
}

/// Lapisan animasi di atas gambar latar.
class AirportPickupOverlayPainter extends CustomPainter {
  /// Posisi animasi 0..1 (satu putaran = [_periodSeconds] detik).
  final double t;
  const AirportPickupOverlayPainter(this.t);

  /// Waktu dalam detik sejak awal siklus.
  double get _seconds => t * _periodSeconds;

  /// Fase 0..1 untuk objek dengan siklus [cycleSeconds] detik.
  double _phase(double cycleSeconds, [double offset = 0]) =>
      ((_seconds / cycleSeconds) + offset) % 1.0;

  /// Denyut halus 0..1 (naik-turun mulus, tanpa sudut tajam).
  double _pulse(double cycleSeconds, [double offset = 0]) =>
      0.5 - 0.5 * math.cos(2 * math.pi * _phase(cycleSeconds, offset));

  @override
  void paint(Canvas canvas, Size size) {
    // Petakan koordinat "fraksi gambar" (0..1) ke kanvas, mengikuti cara
    // BoxFit.cover memotong gambar.
    final s = math.max(size.width / _bgWidth, size.height / _bgHeight);
    final ox = (size.width - _bgWidth * s) / 2;
    final oy = (size.height - _bgHeight * s) / 2;
    Offset at(double fx, double fy) =>
        Offset(ox + fx * _bgWidth * s, oy + fy * _bgHeight * s);
    final u = _bgHeight * s; // 1 satuan = tinggi gambar di layar

    canvas.save();
    canvas.clipRect(Offset.zero & size);

    _paintTwinklingStars(canvas, at, u);
    _paintDriftingWisps(canvas, at, u);
    _paintCrossingPlane(canvas, at, u);
    _paintHorizonBreath(canvas, at, size);
    _paintTowerBeacon(canvas, at, u);
    _paintApronBlinkers(canvas, at, u);
    _paintArrivingHeadlights(canvas, at, u);

    canvas.restore();
  }

  // --- Langit -------------------------------------------------------------

  /// Bintang berkelip di sepertiga atas.
  void _paintTwinklingStars(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
  ) {
    // Posisi acak tapi TETAP (seed konstan) supaya bintang tidak meloncat.
    final rnd = math.Random(11);
    const cycles = [4.0, 5.0, 6.0, 10.0, 12.0];
    final paint = Paint();

    for (var i = 0; i < 22; i++) {
      final fx = rnd.nextDouble();
      final fy = 0.02 + rnd.nextDouble() * 0.38;
      final baseR = (0.0007 + rnd.nextDouble() * 0.0009) * u;
      final glow = _pulse(cycles[i % cycles.length], rnd.nextDouble());

      paint.color = Colors.white.withValues(alpha: 0.18 + 0.55 * glow);
      canvas.drawCircle(at(fx, fy), baseR * (0.75 + 0.5 * glow), paint);
    }
  }

  /// Dua sapuan awan tipis yang hanyut sangat pelan.
  void _paintDriftingWisps(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
  ) {
    final paint = Paint()
      ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.006);

    void wisp(double fy, double offset, double alpha) {
      // Hanyut menyeberang layar lalu kembali dari sisi lain (siklus 60 detik).
      final fx = -0.35 + _phase(60, offset) * 1.7;
      paint.color = Colors.white.withValues(alpha: alpha);
      final c = at(fx, fy);
      canvas.drawOval(
        Rect.fromCenter(center: c, width: u * 0.16, height: u * 0.006),
        paint,
      );
      canvas.drawOval(
        Rect.fromCenter(
          center: c.translate(u * 0.07, u * 0.012),
          width: u * 0.10,
          height: u * 0.005,
        ),
        paint,
      );
    }

    wisp(0.47, 0.10, 0.055);
    wisp(0.57, 0.55, 0.040);
  }

  /// Pesawat kedua yang melintas naik, lengkap dengan jejak kondensasi dan
  /// lampu navigasi berkedip.
  void _paintCrossingPlane(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
  ) {
    final travel = _phase(20); // satu lintasan tiap 20 detik
    final fx = -0.25 + travel * 1.5;
    final fy = 0.34 - travel * 0.16;

    // Muncul & menghilang perlahan di kedua ujung lintasan.
    final fade = (math.min(travel, 1 - travel) / 0.18).clamp(0.0, 1.0);
    if (fade <= 0.01) return;

    final pos = at(fx, fy);

    // Jejak kondensasi: menipis ke belakang.
    final trail = Paint()
      ..strokeCap = StrokeCap.round
      ..strokeWidth = u * 0.0022
      ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.0016);
    for (var i = 1; i <= 5; i++) {
      trail.color = Colors.white.withValues(alpha: 0.16 * fade * (1 - i / 6));
      canvas.drawLine(
        at(fx - 0.035 * i, fy + 0.0038 * i),
        at(fx - 0.035 * (i - 1), fy + 0.0038 * (i - 1)),
        trail,
      );
    }

    // Siluet pesawat kecil.
    final body = Paint()
      ..color = const Color(0xFF0B1E3A).withValues(alpha: 0.85 * fade);
    final len = u * 0.017;
    canvas.drawPath(
      Path()
        ..moveTo(pos.dx + len, pos.dy - len * 0.18)
        ..lineTo(pos.dx - len * 0.55, pos.dy + len * 0.14)
        ..lineTo(pos.dx - len * 0.95, pos.dy + len * 0.02)
        ..lineTo(pos.dx - len * 0.60, pos.dy - len * 0.10)
        ..close(),
      body,
    );
    // Sayap.
    canvas.drawPath(
      Path()
        ..moveTo(pos.dx + len * 0.10, pos.dy - len * 0.06)
        ..lineTo(pos.dx - len * 0.30, pos.dy - len * 0.46)
        ..lineTo(pos.dx - len * 0.02, pos.dy - len * 0.02)
        ..close(),
      body,
    );

    // Lampu navigasi merah, sekali kedip tiap 2 detik.
    if (_phase(2) < 0.12) {
      canvas.drawCircle(
        pos.translate(-len * 0.9, len * 0.02),
        u * 0.0022,
        Paint()
          ..color = const Color(0xFFFF5A4E).withValues(alpha: 0.9 * fade)
          ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.003),
      );
    }
  }

  // --- Cakrawala & terminal ------------------------------------------------

  /// "Napas" cahaya senja di garis cakrawala — menguat dan meredup pelan.
  void _paintHorizonBreath(
    Canvas canvas,
    Offset Function(double, double) at,
    Size size,
  ) {
    final glow = 0.35 + 0.65 * _pulse(30);
    // Pita cahaya senja mengikuti garis langit di aset (kira-kira 0,68–0,80).
    final rect = Rect.fromLTRB(
      0,
      at(0, 0.680).dy,
      size.width,
      at(0, 0.800).dy,
    );

    canvas.drawRect(
      rect,
      Paint()
        ..blendMode = BlendMode.plus
        ..shader = LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [
            const Color(0xFFFFB259).withValues(alpha: 0.0),
            const Color(0xFFFFA24A).withValues(alpha: 0.10 * glow),
            const Color(0xFFFF8C3B).withValues(alpha: 0.05 * glow),
          ],
          stops: const [0.0, 0.62, 1.0],
        ).createShader(rect),
    );
  }

  /// Beacon merah di puncak menara ATC.
  void _paintTowerBeacon(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
  ) {
    // Koordinat diukur dari piksel merah beacon pada aset: gugus tunggal di
    // x 678–684, y 1250–1274 (aset 1080×1935). Wajib diukur ulang setiap kali
    // gambar latarnya diganti — kalau tidak, glow-nya menyala di langit kosong.
    final pos = at(0.6306, 0.6522);
    final glow = _pulse(3);

    canvas.drawCircle(
      pos,
      u * (0.004 + 0.006 * glow),
      Paint()
        ..color = const Color(0xFFFF4438).withValues(alpha: 0.30 + 0.45 * glow)
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.006),
    );
    canvas.drawCircle(
      pos,
      u * 0.0018,
      Paint()
        ..color = const Color(0xFFFFD2CB).withValues(alpha: 0.5 + 0.5 * glow),
    );
  }

  /// Lampu apron berkedip bergantian di depan terminal.
  void _paintApronBlinkers(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
  ) {
    const fy = 0.7735;
    final paint = Paint()
      ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.0035);

    for (var i = 0; i < 7; i++) {
      final glow = _pulse(6, i / 7);
      paint.color = AppColors.lightCyan.withValues(alpha: 0.10 + 0.30 * glow);
      canvas.drawCircle(at(0.06 + i * 0.145, fy), u * 0.0035, paint);
    }
  }

  // --- Jalur penjemputan ---------------------------------------------------

  /// Satu taksi yang datang: sorot lampu depannya menyapu jalur penjemputan
  /// dari kanan ke kiri, meninggalkan pantulan di aspal basah.
  void _paintArrivingHeadlights(
    Canvas canvas,
    Offset Function(double, double) at,
    double u,
  ) {
    final travel = _phase(12);
    final fade = (math.min(travel, 1 - travel) / 0.16).clamp(0.0, 1.0);
    if (fade <= 0.01) return;

    final pos = at(1.20 - travel * 1.45, 0.845 + travel * 0.085);

    // Kerucut cahaya lampu depan, mengarah ke kiri (arah gerak).
    canvas.drawPath(
      Path()
        ..moveTo(pos.dx, pos.dy)
        ..lineTo(pos.dx - u * 0.13, pos.dy - u * 0.022)
        ..lineTo(pos.dx - u * 0.13, pos.dy + u * 0.030)
        ..close(),
      Paint()
        ..blendMode = BlendMode.plus
        ..color = const Color(0xFFFFF0CC).withValues(alpha: 0.13 * fade)
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.012),
    );

    // Titik lampu itu sendiri.
    canvas.drawCircle(
      pos,
      u * 0.010,
      Paint()
        ..blendMode = BlendMode.plus
        ..color = const Color(0xFFFFF6E2).withValues(alpha: 0.30 * fade)
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.010),
    );

    // Pantulan memanjang di aspal basah.
    canvas.drawOval(
      Rect.fromCenter(
        center: pos.translate(0, u * 0.045),
        width: u * 0.030,
        height: u * 0.075,
      ),
      Paint()
        ..blendMode = BlendMode.plus
        ..color = AppColors.lightCyan.withValues(alpha: 0.10 * fade)
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, u * 0.014),
    );
  }

  @override
  bool shouldRepaint(covariant AirportPickupOverlayPainter oldDelegate) =>
      oldDelegate.t != t;
}
