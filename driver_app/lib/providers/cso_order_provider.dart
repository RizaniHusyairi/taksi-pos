import 'package:flutter/foundation.dart';
import '../services/api_service.dart';
import '../utils/api_error.dart';
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

  /// Supir yang siap menerima order (urutan sudah dari server).
  List<QueueDriver> get readyDrivers =>
      drivers.where((d) => d.isReady).toList(growable: false);

  /// Supir yang sedang mendapat giliran — dipilih otomatis oleh sistem.
  /// Sumber utamanya flag `is_next` dari backend; fallback ke elemen pertama
  /// agar tetap benar bila aplikasi terhubung ke backend versi lama.
  QueueDriver? get nextInQueue {
    final ready = readyDrivers;
    if (ready.isEmpty) return null;
    for (final d in ready) {
      if (d.isNext) return d;
    }
    return ready.first;
  }

  /// Sisa antrian di luar supir yang sedang giliran (jalur "pilih supir lain").
  List<QueueDriver> get otherDrivers {
    final top = nextInQueue;
    if (top == null) return const [];
    return readyDrivers.where((d) => d.id != top.id).toList(growable: false);
  }

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
      zonesError = apiErrorMessage(e);
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
      driversError = apiErrorMessage(e);
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
