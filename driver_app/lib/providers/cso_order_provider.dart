import 'package:flutter/foundation.dart';
import '../services/api_service.dart';
import '../models/zone.dart';
import '../models/queue_driver.dart';

enum CsoPaymentMethod { qris, cashCso, cashDriver }

extension CsoPaymentMethodX on CsoPaymentMethod {
  /// Nilai yang diharapkan backend (kolom transactions.method).
  String get apiValue => switch (this) {
    CsoPaymentMethod.qris => 'QRIS',
    CsoPaymentMethod.cashCso => 'CashCSO',
    CsoPaymentMethod.cashDriver => 'CashDriver',
  };

  String get label => switch (this) {
    CsoPaymentMethod.qris => 'QRIS',
    CsoPaymentMethod.cashCso => 'Tunai ke Kasir',
    CsoPaymentMethod.cashDriver => 'Tunai ke Supir',
  };
}

/// State alur pemesanan CSO: Tujuan → Bayar → Pilih Supir → process-order.
///
/// Meniru perilaku web (cso.js): pembayaran diverifikasi dulu, baru supir
/// dipilih, lalu satu panggilan `process-order` membuat booking + transaksi.
class CsoOrderProvider with ChangeNotifier {
  final ApiService _api = ApiService();

  // --- Zona ---
  List<Zone> zones = [];
  bool loadingZones = false;
  String? zonesError;
  Zone? selectedZone;

  // --- QRIS perusahaan ---
  String? companyQrisUrl;
  bool qrisLoaded = false;

  // --- Payload pembayaran (terverifikasi, menunggu finalisasi) ---
  bool paymentVerified = false;
  CsoPaymentMethod? method;
  String passengerPhone = '';
  String? proofPath;

  // --- Antrian supir ---
  List<QueueDriver> drivers = [];
  bool loadingDrivers = false;
  String? driversError;

  // --- Submit ---
  bool submitting = false;

  /// Langkah yang sudah selesai (0..2) untuk indikator stepper.
  int get completedStep => paymentVerified ? 2 : (selectedZone != null ? 1 : 0);

  Future<void> loadZones() async {
    loadingZones = true;
    zonesError = null;
    notifyListeners();
    try {
      final res = await _api.getCsoZones();
      zones = (res.data as List)
          .map((e) => Zone.fromJson((e as Map).cast<String, dynamic>()))
          .toList();
    } catch (e) {
      zonesError = 'Gagal memuat tujuan.';
    } finally {
      loadingZones = false;
      notifyListeners();
    }
  }

  Future<void> loadCompanyQris() async {
    try {
      final res = await _api.getCsoCompanyQris();
      companyQrisUrl = res.data['company_qris_url'] as String?;
    } catch (_) {
      companyQrisUrl = null;
    } finally {
      qrisLoaded = true;
      notifyListeners();
    }
  }

  void selectZone(Zone zone) {
    selectedZone = zone;
    // Mengganti tujuan membatalkan pembayaran yang sudah diinput (seperti web).
    paymentVerified = false;
    method = null;
    proofPath = null;
    notifyListeners();
  }

  /// Tandai pembayaran terverifikasi (belum dikirim ke API — menunggu pilih supir).
  void verifyPayment({
    required CsoPaymentMethod method,
    required String passengerPhone,
    String? proofPath,
  }) {
    this.method = method;
    this.passengerPhone = passengerPhone;
    this.proofPath = proofPath;
    paymentVerified = true;
    notifyListeners();
  }

  Future<void> loadDrivers() async {
    loadingDrivers = true;
    driversError = null;
    notifyListeners();
    try {
      final res = await _api.getCsoAvailableDrivers();
      drivers = (res.data as List)
          .map((e) => QueueDriver.fromJson((e as Map).cast<String, dynamic>()))
          .toList();
    } catch (e) {
      driversError = 'Gagal memuat antrian.';
    } finally {
      loadingDrivers = false;
      notifyListeners();
    }
  }

  /// Finalisasi: kirim process-order. Mengembalikan data booking (untuk struk).
  Future<Map<String, dynamic>> finalizeOrder(int driverId) async {
    if (!paymentVerified || method == null || selectedZone == null) {
      throw StateError('Pembayaran belum diverifikasi.');
    }
    submitting = true;
    notifyListeners();
    try {
      final res = await _api.csoProcessOrder(
        driverId: driverId,
        zoneId: selectedZone!.id,
        method: method!.apiValue,
        passengerPhone: passengerPhone,
        proofImagePath: proofPath,
      );
      final data = (res.data['data'] as Map).cast<String, dynamic>();
      return data;
    } finally {
      submitting = false;
      notifyListeners();
    }
  }

  /// Reset setelah order sukses (atau dibatalkan) — siap untuk order berikutnya.
  void reset() {
    selectedZone = null;
    paymentVerified = false;
    method = null;
    passengerPhone = '';
    proofPath = null;
    submitting = false;
    notifyListeners();
  }
}
