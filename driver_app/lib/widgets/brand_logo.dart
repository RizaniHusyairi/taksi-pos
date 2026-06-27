import 'package:flutter/material.dart';
import '../theme/app_colors.dart';

/// Logo Taxi Angkasa Jaya di dalam wadah putih agar warnanya kontras
/// di atas latar biru.
class BrandLogo extends StatelessWidget {
  final double height;
  final EdgeInsets padding;
  final double radius;
  final bool withShadow;

  const BrandLogo({
    super.key,
    this.height = 54,
    this.padding = const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
    this.radius = 22,
    this.withShadow = true,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: padding,
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(radius),
        boxShadow: withShadow
            ? [
                BoxShadow(
                  color: AppColors.deepBlue.withValues(alpha: 0.20),
                  blurRadius: 24,
                  offset: const Offset(0, 10),
                ),
              ]
            : null,
      ),
      child: Image.asset(
        'assets/images/logo_taksi.png',
        height: height,
        fit: BoxFit.contain,
      ),
    );
  }
}
