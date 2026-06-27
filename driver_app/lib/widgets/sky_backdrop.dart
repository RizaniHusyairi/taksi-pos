import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import 'clouds.dart';

/// Latar langit penuh layar dengan awan & pesawat beranimasi.
/// Hanya satu AnimationController -> hemat baterai.
class SkyBackdrop extends StatefulWidget {
  final Widget child;
  const SkyBackdrop({super.key, required this.child});

  @override
  State<SkyBackdrop> createState() => _SkyBackdropState();
}

class _SkyBackdropState extends State<SkyBackdrop>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(seconds: 22),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        const Positioned.fill(
          child: DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [AppColors.deepBlue, AppColors.skyBlue, AppColors.cyan],
              ),
            ),
          ),
        ),
        Positioned.fill(
          child: RepaintBoundary(
            child: AnimatedBuilder(
              animation: _controller,
              builder: (context, _) => CustomPaint(
                painter: AnimatedSkyPainter(_controller.value),
              ),
            ),
          ),
        ),
        widget.child,
      ],
    );
  }
}
