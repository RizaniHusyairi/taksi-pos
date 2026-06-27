import 'dart:math' as math;
import 'package:flutter/material.dart';

/// Menggambar ikon Material di canvas (dipakai untuk siluet pesawat).
void _paintIcon(
  Canvas canvas,
  IconData icon,
  Offset center,
  double size,
  Color color, {
  double angle = 0,
}) {
  final tp = TextPainter(textDirection: TextDirection.ltr);
  tp.text = TextSpan(
    text: String.fromCharCode(icon.codePoint),
    style: TextStyle(
      fontFamily: icon.fontFamily,
      package: icon.fontPackage,
      fontSize: size,
      color: color,
    ),
  );
  tp.layout();
  canvas.save();
  canvas.translate(center.dx, center.dy);
  canvas.rotate(angle);
  tp.paint(canvas, Offset(-tp.width / 2, -tp.height / 2));
  canvas.restore();
}

void _cloud(Canvas canvas, Offset c, double r, Paint p) {
  canvas.drawCircle(c, r, p);
  canvas.drawCircle(c.translate(r * 0.95, r * 0.12), r * 0.78, p);
  canvas.drawCircle(c.translate(-r * 0.95, r * 0.18), r * 0.7, p);
  canvas.drawOval(
    Rect.fromCenter(
      center: c.translate(0, r * 0.55),
      width: r * 3.6,
      height: r * 1.5,
    ),
    p,
  );
}

/// Awan statis (tanpa animasi) untuk header layar — ringan.
class CloudPainter extends CustomPainter {
  final Color color;
  final double opacity;
  const CloudPainter({this.color = Colors.white, this.opacity = 0.12});

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()..color = color.withValues(alpha: opacity);
    _cloud(canvas, Offset(size.width * 0.16, size.height * 0.62), 20, p);
    _cloud(canvas, Offset(size.width * 0.78, size.height * 0.32), 26, p);
    _paintIcon(
      canvas,
      Icons.flight,
      Offset(size.width * 0.9, size.height * 0.7),
      30,
      color.withValues(alpha: opacity + 0.06),
      angle: 0.7,
    );
  }

  @override
  bool shouldRepaint(covariant CloudPainter oldDelegate) => false;
}

/// Langit beranimasi: awan menyapu + pesawat terbang. Dipakai hanya di
/// layar login (satu controller) agar tetap ringan.
class AnimatedSkyPainter extends CustomPainter {
  final double t; // 0..1
  AnimatedSkyPainter(this.t);

  @override
  void paint(Canvas canvas, Size size) {
    final cloud = Paint()..color = Colors.white.withValues(alpha: 0.14);

    // Beberapa awan dengan kecepatan & ketinggian berbeda.
    final clouds = <List<double>>[
      // [yFactor, radius, speed, phase]
      [0.18, 30, 0.6, 0.0],
      [0.30, 22, 1.0, 0.4],
      [0.52, 26, 0.45, 0.7],
      [0.70, 18, 0.85, 0.2],
    ];
    for (final c in clouds) {
      final span = size.width + 160;
      final x = ((t * c[2] + c[3]) % 1.0) * span - 80;
      _cloud(canvas, Offset(x, size.height * c[0]), c[1], cloud);
    }

    // Pesawat melintas diagonal lembut.
    final span = size.width + 160;
    final px = (t % 1.0) * span - 80;
    final py = size.height * 0.26 + math.sin(t * math.pi * 2) * 14;
    _paintIcon(
      canvas,
      Icons.flight,
      Offset(px, py),
      40,
      Colors.white.withValues(alpha: 0.92),
      angle: 0.65,
    );
  }

  @override
  bool shouldRepaint(covariant AnimatedSkyPainter oldDelegate) =>
      oldDelegate.t != t;
}
