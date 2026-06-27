import 'package:flutter/material.dart';

/// Animasi masuk sekali-jalan (fade + geser ke atas). Ringan: controller
/// hanya forward sekali lalu di-dispose.
class FadeInUp extends StatefulWidget {
  final Widget child;
  final int delayMs;
  final double dy; // fraksi tinggi child (0.12 = 12%)
  final Duration duration;

  const FadeInUp({
    super.key,
    required this.child,
    this.delayMs = 0,
    this.dy = 0.14,
    this.duration = const Duration(milliseconds: 480),
  });

  @override
  State<FadeInUp> createState() => _FadeInUpState();
}

class _FadeInUpState extends State<FadeInUp>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: widget.duration,
  );
  late final Animation<double> _fade = CurvedAnimation(
    parent: _c,
    curve: Curves.easeOut,
  );
  late final Animation<Offset> _slide = Tween<Offset>(
    begin: Offset(0, widget.dy),
    end: Offset.zero,
  ).animate(CurvedAnimation(parent: _c, curve: Curves.easeOutCubic));

  @override
  void initState() {
    super.initState();
    if (widget.delayMs <= 0) {
      _c.forward();
    } else {
      Future.delayed(Duration(milliseconds: widget.delayMs), () {
        if (mounted) _c.forward();
      });
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _fade,
      child: SlideTransition(position: _slide, child: widget.child),
    );
  }
}
