import '../services/api_service.dart';

/// Item riwayat transaksi CSO (sumber: GET /cso/history).
/// Berisi data transaksi + booking yang termuat (zona, supir, cso).
class CsoTransaction {
  final int id; // id transaksi
  final num amount;
  final String method; // QRIS / CashCSO / CashDriver
  final String? paymentProof; // path relatif di storage publik
  final DateTime? createdAt;

  // --- data booking ---
  final int? bookingId;
  final String status; // Assigned / OnTrip / Completed / Cancelled / ...
  final String passengerPhone;
  final DateTime? bookingCreatedAt;
  final String zoneName;
  final String driverName;
  final int? driverLine;

  /// Map mentah (untuk diteruskan ke struk).
  final Map<String, dynamic> raw;
  final Map<String, dynamic> bookingRaw;

  const CsoTransaction({
    required this.id,
    required this.amount,
    required this.method,
    required this.status,
    required this.passengerPhone,
    required this.zoneName,
    required this.driverName,
    required this.raw,
    required this.bookingRaw,
    this.paymentProof,
    this.createdAt,
    this.bookingId,
    this.bookingCreatedAt,
    this.driverLine,
  });

  bool get isQris => method == 'QRIS';
  bool get hasProof => isQris && (paymentProof?.isNotEmpty ?? false);

  /// URL lengkap bukti pembayaran (atau null bila tidak ada).
  String? get proofUrl =>
      hasProof ? '${ApiService.assetBaseUrl}/storage/$paymentProof' : null;

  /// Hanya order ber-status 'Assigned' yang boleh diganti supirnya.
  bool get canChangeDriver => status == 'Assigned';

  /// Menit sejak booking dibuat (untuk aturan jeda 10 menit ganti supir).
  int get minutesSinceBooking {
    if (bookingCreatedAt == null) return 9999;
    return DateTime.now().difference(bookingCreatedAt!).inMinutes;
  }

  /// Data berbentuk booking + transaksi untuk ditampilkan sebagai struk.
  Map<String, dynamic> get receiptData => {...bookingRaw, 'transaction': raw};

  factory CsoTransaction.fromJson(Map<String, dynamic> json) {
    final booking =
        (json['booking'] as Map?)?.cast<String, dynamic>() ?? const {};
    final zone =
        (booking['zone_to'] as Map?)?.cast<String, dynamic>() ?? const {};
    final driver =
        (booking['driver'] as Map?)?.cast<String, dynamic>() ?? const {};
    final profile =
        (driver['driver_profile'] as Map?)?.cast<String, dynamic>() ?? const {};

    num toNum(dynamic v) =>
        v is num ? v : num.tryParse(v?.toString() ?? '0') ?? 0;
    int? toInt(dynamic v) => v == null ? null : int.tryParse(v.toString());
    DateTime? toDate(dynamic v) =>
        v == null ? null : DateTime.tryParse(v.toString());

    return CsoTransaction(
      id: json['id'] as int,
      amount: toNum(json['amount']),
      method: (json['method'] ?? '-').toString(),
      paymentProof: json['payment_proof']?.toString(),
      createdAt: toDate(json['created_at']),
      bookingId: toInt(booking['id']),
      status: (booking['status'] ?? 'Assigned').toString(),
      passengerPhone: (booking['passenger_phone'] ?? '-').toString(),
      bookingCreatedAt: toDate(booking['created_at']),
      zoneName: (zone['name'] ?? 'Unknown').toString(),
      driverName: (driver['name'] ?? '-').toString(),
      driverLine: toInt(profile['line_number']),
      raw: json,
      bookingRaw: booking,
    );
  }
}
