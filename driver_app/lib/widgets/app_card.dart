import 'package:flutter/material.dart';
import '../theme/app_colors.dart';

/// Kartu putih dengan sudut membulat & bayangan halus (tanpa blur -> ringan).
class AppCard extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;
  final Color color;
  final Gradient? gradient;
  final BorderRadius? radius;
  final Border? border;
  final List<BoxShadow>? shadow;

  const AppCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(18),
    this.color = AppColors.surface,
    this.gradient,
    this.radius,
    this.border,
    this.shadow,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: padding,
      decoration: BoxDecoration(
        color: gradient == null ? color : null,
        gradient: gradient,
        borderRadius: radius ?? BorderRadius.circular(22),
        border: border,
        boxShadow: shadow ??
            [
              BoxShadow(
                color: AppColors.deepBlue.withValues(alpha: 0.07),
                blurRadius: 22,
                offset: const Offset(0, 10),
              ),
            ],
      ),
      child: child,
    );
  }
}
