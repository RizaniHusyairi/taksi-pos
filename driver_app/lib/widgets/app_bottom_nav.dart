import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import '../theme/app_colors.dart';

class NavItemData {
  final IconData icon;
  final String label;
  const NavItemData(this.icon, this.label);
}

/// Bottom navigation kustom berbentuk dock mengambang.
///
/// Bila [centerIndex] diisi, item pada posisi itu diangkat menjadi tombol bulat
/// besar yang menonjol di atas dock — dipakai untuk aksi utama (CSO: "Pesan").
/// Tanpa [centerIndex] tampilannya tetap seperti semula (dipakai layar supir).
class AppBottomNav extends StatelessWidget {
  final int currentIndex;
  final ValueChanged<int> onTap;
  final List<NavItemData> items;
  final int? centerIndex;

  const AppBottomNav({
    super.key,
    required this.currentIndex,
    required this.onTap,
    required this.items,
    this.centerIndex,
  });

  static const double _dockHeight = 70;
  static const double _fabSize = 62;

  /// Seberapa jauh tombol tengah menyembul di atas bibir dock.
  static const double _fabRaise = 22;

  @override
  Widget build(BuildContext context) {
    final dock = _buildDock();

    if (centerIndex == null) {
      return SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 0, 14, 12),
          child: dock,
        ),
      );
    }

    // Tinggi total harus memuat dock + bagian tombol yang menyembul, kalau
    // tidak Stack-nya terpotong dan tombolnya ikut hilang separuh.
    final stackHeight = _dockHeight + _fabRaise + 4;

    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 0, 14, 12),
        child: SizedBox(
          height: stackHeight,
          child: Stack(
            clipBehavior: Clip.none,
            alignment: Alignment.bottomCenter,
            children: [
              Positioned(left: 0, right: 0, bottom: 0, child: dock),
              Positioned(
                bottom: _dockHeight - _fabSize + _fabRaise,
                child: _CenterNavButton(
                  data: items[centerIndex!],
                  selected: currentIndex == centerIndex,
                  size: _fabSize,
                  onTap: () => onTap(centerIndex!),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildDock() {
    return Container(
      height: _dockHeight,
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: Colors.white, width: 1),
        boxShadow: const [
          BoxShadow(
            color: Color(0x2E0B4DA2),
            blurRadius: 28,
            spreadRadius: -6,
            offset: Offset(0, 12),
          ),
          BoxShadow(
            color: Color(0x140B4DA2),
            blurRadius: 6,
            offset: Offset(0, 2),
          ),
        ],
      ),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
      child: Row(
        children: List.generate(items.length, (i) {
          // Slot tengah hanya menyisakan ruang; tombolnya digambar di Stack
          // supaya bisa menyembul keluar dari dock.
          if (i == centerIndex) {
            return const SizedBox(width: _fabSize + 16);
          }
          // Setiap item mendapat slot selebar sama (Expanded, bukan Flexible
          // berbobot). Dengan begitu posisi ikon TETAP saat tab berpindah —
          // versi sebelumnya melebarkan item terpilih sehingga seluruh baris
          // ikut bergeser dan terlihat berantakan.
          return Expanded(
            child: _NavButton(
              data: items[i],
              selected: i == currentIndex,
              onTap: () {
                if (i != currentIndex) HapticFeedback.selectionClick();
                onTap(i);
              },
            ),
          );
        }),
      ),
    );
  }
}

class _NavButton extends StatelessWidget {
  final NavItemData data;
  final bool selected;
  final VoidCallback onTap;

  const _NavButton({
    required this.data,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = AppColors.royalBlue;

    Widget icon = Icon(
      data.icon,
      size: 22,
      color: selected ? accent : AppColors.inkSoft,
    );

    // Bounce sekali saat item baru terpilih: mengganti tipe widget membuat
    // TweenAnimationBuilder mulai dari awal setiap kali `selected` jadi true.
    if (selected) {
      icon = TweenAnimationBuilder<double>(
        tween: Tween(begin: 0.82, end: 1),
        duration: const Duration(milliseconds: 460),
        curve: Curves.elasticOut,
        builder: (_, value, child) =>
            Transform.scale(scale: value, child: child),
        child: icon,
      );
    }

    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: onTap,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        mainAxisSize: MainAxisSize.min,
        children: [
          // Kapsul tint sebagai penanda aktif. Ukurannya tetap, jadi tidak ada
          // pergeseran tata letak saat berpindah tab — hanya warnanya yang
          // beranimasi.
          AnimatedContainer(
            duration: const Duration(milliseconds: 260),
            curve: Curves.easeOutCubic,
            width: 42,
            height: 26,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: selected
                  ? AppColors.cyan.withValues(alpha: 0.15)
                  : Colors.transparent,
              borderRadius: BorderRadius.circular(13),
            ),
            child: icon,
          ),
          const SizedBox(height: 3),
          AnimatedDefaultTextStyle(
            duration: const Duration(milliseconds: 260),
            curve: Curves.easeOutCubic,
            style: GoogleFonts.outfit(
              color: selected ? accent : AppColors.inkSoft,
              fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
              fontSize: 10.5,
              height: 1.1,
            ),
            child: Text(
              data.label,
              maxLines: 1,
              softWrap: false,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
            ),
          ),
        ],
      ),
    );
  }
}

/// Tombol aksi utama di tengah dock.
///
/// Tiga lapis animasi, masing-masing punya tugas berbeda:
///  - cincin sapuan yang berputar pelan  -> menandai ini tombol "hidup";
///  - denyut halo saat TIDAK terpilih    -> mengundang untuk ditekan;
///  - letupan + putaran ikon saat ditekan -> umpan balik seketika.
class _CenterNavButton extends StatefulWidget {
  final NavItemData data;
  final bool selected;
  final double size;
  final VoidCallback onTap;

  const _CenterNavButton({
    required this.data,
    required this.selected,
    required this.size,
    required this.onTap,
  });

  @override
  State<_CenterNavButton> createState() => _CenterNavButtonState();
}

class _CenterNavButtonState extends State<_CenterNavButton>
    with TickerProviderStateMixin {
  late final AnimationController _ambient = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 3200),
  )..repeat();

  late final AnimationController _tap = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 640),
  );

  @override
  void dispose() {
    _ambient.dispose();
    _tap.dispose();
    super.dispose();
  }

  void _handleTap() {
    HapticFeedback.mediumImpact();
    _tap.forward(from: 0);
    widget.onTap();
  }

  @override
  Widget build(BuildContext context) {
    final size = widget.size;
    // Area sentuh dibatasi selebar bagian yang terlihat (tombol + kerah).
    // Kalau kotaknya dibuat selebar halo, tombol ini akan mencuri sentuhan
    // milik item tetangga di kiri-kanannya.
    final core = size + 14;
    // Halo digambar melampaui area sentuh lewat OverflowBox di bawah.
    final haloBox = size + 52;

    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: _handleTap,
      child: SizedBox(
        width: core,
        height: core,
        child: RepaintBoundary(
          child: AnimatedBuilder(
            animation: Listenable.merge([_ambient, _tap]),
            builder: (context, _) {
              final t = _ambient.value;
              final tapT = _tap.value;

              // Tekan -> mengempis sebentar lalu memantul balik.
              final press = tapT == 0
                  ? 1.0
                  : 1 - 0.12 * math.sin(tapT * math.pi) * (1 - tapT);

              return Stack(
                clipBehavior: Clip.none,
                alignment: Alignment.center,
                children: [
                  OverflowBox(
                    maxWidth: haloBox,
                    maxHeight: haloBox,
                    child: CustomPaint(
                      size: Size(haloBox, haloBox),
                      painter: _CenterHaloPainter(
                        progress: t,
                        burst: tapT,
                        idle: !widget.selected,
                        // Denyut mulai dari bibir kerah, bukan dari tepi
                        // tombol — kalau tidak, awal animasinya tertutup.
                        startRadius: size / 2 + 8,
                      ),
                    ),
                  ),

                  // Kerah putih: memisahkan tombol dari konten halaman yang
                  // lewat di belakangnya, sekaligus membuatnya tampak duduk
                  // rapi pada dock alih-alih sekadar menumpuk. Digambar SEBELUM
                  // cincin sapuan, kalau tidak cincinnya tertutup.
                  Container(
                    width: size + 14,
                    height: size + 14,
                    decoration: const BoxDecoration(
                      shape: BoxShape.circle,
                      color: Colors.white,
                    ),
                  ),

                  // Cincin sapuan berputar — beda fase dari halo supaya
                  // gerakannya tidak terasa satu irama.
                  Transform.rotate(
                    angle: t * 2 * math.pi,
                    child: Container(
                      width: size + 7,
                      height: size + 7,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: SweepGradient(
                          colors: [
                            Color(0x0029ABE2),
                            Color(0xCC29ABE2),
                            Color(0x005BC2EC),
                            Color(0x991565C0),
                            Color(0x0029ABE2),
                          ],
                          stops: [0.0, 0.28, 0.5, 0.78, 1.0],
                        ),
                      ),
                    ),
                  ),

                  Transform.scale(
                    scale: press,
                    child: Container(
                      width: size,
                      height: size,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: AppColors.buttonGradient,
                        boxShadow: [
                          BoxShadow(
                            color: AppColors.cyan.withValues(alpha: 0.45),
                            blurRadius: 20,
                            spreadRadius: -2,
                            offset: const Offset(0, 8),
                          ),
                          BoxShadow(
                            color: AppColors.deepBlue.withValues(alpha: 0.30),
                            blurRadius: 8,
                            offset: const Offset(0, 3),
                          ),
                        ],
                      ),
                      child: Center(
                        child: Transform.rotate(
                          // Setengah putaran saat ditekan, meredam di akhir.
                          angle: Curves.easeOutBack.transform(tapT) * math.pi,
                          child: Icon(
                            widget.data.icon,
                            size: 28,
                            color: Colors.white,
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _CenterHaloPainter extends CustomPainter {
  final double progress; // 0..1 berulang
  final double burst; // 0..1 sekali tekan
  final bool idle; // tampilkan denyut pengundang
  final double startRadius;

  _CenterHaloPainter({
    required this.progress,
    required this.burst,
    required this.idle,
    required this.startRadius,
  });

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);

    // Dua denyut beriringan, hanya saat tab ini tidak sedang aktif.
    if (idle) {
      for (var i = 0; i < 2; i++) {
        final p = (progress + i * 0.5) % 1.0;
        final r = startRadius + p * 16;
        final opacity = (1 - p) * 0.28;
        if (opacity <= 0.01) continue;
        canvas.drawCircle(
          center,
          r,
          Paint()
            ..style = PaintingStyle.stroke
            ..strokeWidth = 2
            ..color = AppColors.cyan.withValues(alpha: opacity),
        );
      }
    }

    // Letupan sekali saat ditekan.
    if (burst > 0 && burst < 1) {
      final r = startRadius + burst * 20;
      canvas.drawCircle(
        center,
        r,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 3 * (1 - burst)
          ..color = AppColors.cyan.withValues(alpha: (1 - burst) * 0.55),
      );
    }
  }

  @override
  bool shouldRepaint(_CenterHaloPainter old) =>
      old.progress != progress || old.burst != burst || old.idle != idle;
}
