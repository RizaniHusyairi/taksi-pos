// Model untuk fitur setoran tunai SUPIR ke admin (pelunasan utang komisi).
//
// Sengaja dibuat kembar dengan models/cso_deposit.dart — alur di layarnya sama
// persis, hanya arah uangnya yang berbeda. Satu perbedaan yang penting untuk
// diingat saat membaca angka di sini: `total`/`amount` adalah KOMISI yang
// terutang, bukan tarif penuh penumpang.

/// Rekap utang komisi yang belum disetor untuk satu tanggal.
/// Sumber: GET /driver/deposits/outstanding
class DriverOutstandingDay {
  /// Format 'YYYY-MM-DD' — dikirim apa adanya saat menyetor, jangan diformat ulang.
  final String date;
  final num total;
  final int count;

  const DriverOutstandingDay({
    required this.date,
    required this.total,
    required this.count,
  });

  factory DriverOutstandingDay.fromJson(Map<String, dynamic> json) {
    return DriverOutstandingDay(
      date: (json['date'] ?? '').toString(),
      total: json['total'] is num
          ? json['total'] as num
          : num.tryParse(json['total']?.toString() ?? '0') ?? 0,
      count: json['count'] is int
          ? json['count'] as int
          : int.tryParse(json['count']?.toString() ?? '0') ?? 0,
    );
  }

  DateTime? get parsedDate => DateTime.tryParse(date);
}

/// Satu pengajuan setoran supir.
/// Sumber: GET /driver/deposits, POST /driver/deposits
class DriverDeposit {
  final int id;
  final num amount;

  /// Pending | Approved | Rejected
  final String status;

  /// Tanggal-tanggal yang tercakup ('YYYY-MM-DD').
  final List<String> periodDates;
  final int transactionsCount;
  final String? note;

  /// Alasan penolakan / catatan admin — inilah yang perlu dibaca supir saat ditolak.
  final String? adminNote;
  final DateTime? submittedAt;
  final DateTime? processedAt;

  const DriverDeposit({
    required this.id,
    required this.amount,
    required this.status,
    required this.periodDates,
    required this.transactionsCount,
    this.note,
    this.adminNote,
    this.submittedAt,
    this.processedAt,
  });

  factory DriverDeposit.fromJson(Map<String, dynamic> json) {
    num toNum(dynamic v) =>
        v is num ? v : (num.tryParse(v?.toString() ?? '0') ?? 0);

    final rawDates = json['period_dates'];
    final dates = rawDates is List
        ? rawDates.map((e) => e.toString()).toList()
        : <String>[];

    return DriverDeposit(
      id: toNum(json['id']).toInt(),
      amount: toNum(json['amount']),
      status: (json['status'] ?? 'Pending').toString(),
      periodDates: dates,
      transactionsCount: toNum(json['transactions_count']).toInt(),
      note: (json['note']?.toString().trim().isEmpty ?? true)
          ? null
          : json['note'].toString(),
      adminNote: (json['admin_note']?.toString().trim().isEmpty ?? true)
          ? null
          : json['admin_note'].toString(),
      submittedAt:
          DateTime.tryParse(json['submitted_at']?.toString() ?? '')?.toLocal(),
      processedAt:
          DateTime.tryParse(json['processed_at']?.toString() ?? '')?.toLocal(),
    );
  }

  bool get isPending => status == 'Pending';
  bool get isApproved => status == 'Approved';
  bool get isRejected => status == 'Rejected';
}
