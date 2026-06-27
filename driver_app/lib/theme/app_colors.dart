import 'package:flutter/material.dart';

/// Palet warna "Langit Bandara" yang diturunkan dari logo Taxi Angkasa Jaya.
class AppColors {
  AppColors._();

  // Biru utama dari logo (gelap -> terang)
  static const Color deepBlue = Color(0xFF0B4DA2); // biru tua logo
  static const Color royalBlue = Color(0xFF1565C0);
  static const Color skyBlue = Color(0xFF1E88E5);
  static const Color cyan = Color(0xFF29ABE2); // cyan logo
  static const Color lightCyan = Color(0xFF5BC2EC);

  // Latar & permukaan
  static const Color background = Color(0xFFF2F7FD);
  static const Color surface = Colors.white;
  static const Color paleBlue = Color(0xFFEAF3FF);

  // Teks
  static const Color ink = Color(0xFF0D2138); // navy gelap
  static const Color inkSoft = Color(0xFF5E708A);
  static const Color inkFaint = Color(0xFF9AAAC0);

  // Status
  static const Color success = Color(0xFF18B27E);
  static const Color warning = Color(0xFFF5A623);
  static const Color danger = Color(0xFFEF5350);
  static const Color gold = Color(0xFFE9B949);

  // Gradien
  static const LinearGradient skyGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [deepBlue, skyBlue, cyan],
  );

  static const LinearGradient buttonGradient = LinearGradient(
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
    colors: [skyBlue, cyan],
  );

  static const LinearGradient nightGradient = LinearGradient(
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
    colors: [Color(0xFF0A3C7D), Color(0xFF0B4DA2)],
  );
}
