import 'package:flutter/foundation.dart';
import '../utils/api_error.dart';

/// Status online/offline global aplikasi.
///
/// Di-update OTOMATIS oleh interceptor Dio di [ApiService]: setiap respons
/// sukses menandai online, setiap error koneksi menandai offline. Banner
/// offline global menyimak perubahan ini lewat [ListenableBuilder].
///
/// Pendekatan reaktif ini lebih akurat daripada sekadar cek antarmuka jaringan
/// (Wi-Fi menyala ≠ internet jalan, mis. captive portal bandara): yang dipakai
/// adalah apakah panggilan API benar-benar tembus.
class AppConnectivity extends ChangeNotifier {
  AppConnectivity._();
  static final AppConnectivity instance = AppConnectivity._();

  bool _isOnline = true;
  bool get isOnline => _isOnline;

  /// Dipanggil saat sebuah panggilan API berhasil → kembali online.
  void reportSuccess() => _set(true);

  /// Dipanggil saat panggilan API gagal. Hanya error KONEKSI yang menandai
  /// offline; error server (4xx/5xx) berarti internet tetap jalan.
  void reportError(Object? error) {
    if (isConnectionError(error)) _set(false);
  }

  void _set(bool value) {
    if (_isOnline == value) return;
    _isOnline = value;
    notifyListeners();
  }
}
