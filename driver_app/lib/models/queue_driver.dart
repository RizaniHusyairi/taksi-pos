/// Supir dalam antrian (sumber: GET /cso/available-drivers).
///
/// Catatan: backend menaruh `queue_score` di dalam objek `driver_profile`
/// (= sort_order). Nilai < 1000 berarti antrian utama; >= 1000 berarti
/// "rejoin" (masuk lagi setelah keluar). Web membaca `queue_score` di level
/// teratas sehingga nomor antrian tidak pernah muncul — di sini kita baca dari
/// tempat yang benar agar nomor & pemisah "Rejoin" berfungsi.
class QueueDriver {
  final int id;
  final String name;
  final String? carModel;
  final String? plateNumber;
  final int? lineNumber;
  final String status; // standby / available / offline
  final int queueScore;

  const QueueDriver({
    required this.id,
    required this.name,
    required this.status,
    required this.queueScore,
    this.carModel,
    this.plateNumber,
    this.lineNumber,
  });

  bool get isReady => status == 'standby' || status == 'available';
  bool get isRejoin => queueScore >= 1000;

  /// Nomor antrian yang ditampilkan (1-based) atau null bila rejoin/tak relevan.
  int? get queueNumber => queueScore < 1000 ? queueScore + 1 : null;

  factory QueueDriver.fromJson(Map<String, dynamic> json) {
    final profile =
        (json['driver_profile'] as Map?)?.cast<String, dynamic>() ?? const {};
    int? toInt(dynamic v) => v == null ? null : int.tryParse(v.toString());

    return QueueDriver(
      id: json['id'] as int,
      name: (json['name'] ?? '-').toString(),
      carModel: profile['car_model']?.toString(),
      plateNumber: profile['plate_number']?.toString(),
      lineNumber: toInt(profile['line_number']),
      status: (profile['status'] ?? 'offline').toString(),
      queueScore: toInt(profile['queue_score']) ?? 0,
    );
  }
}
