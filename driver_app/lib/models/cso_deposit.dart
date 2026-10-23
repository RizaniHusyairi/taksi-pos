// Model untuk fitur setoran tunai CSO ke admin.
//
// Dua bentuk data:
//   OutstandingDay — satu TANGGAL yang tunainya belum disetor (yang dicentang CSO)
//   CsoDeposit     — satu kali setoran yang sudah diajukan (riwayat)

/// Rekap tunai belum disetor untuk satu tanggal.
/// Sumber: GET /cso/deposits/outstanding
class OutstandingDay {
  /// Format 'YYYY-MM-DD' — dikirim apa adanya saat menyetor, jangan diformat ulang.
  final String date;
  final num total;
  final int count;

  const OutstandingDay({
    required this.date,
    required this.total,
    required this.count,
  });

  factory OutstandingDay.fromJson(Map<String, dynamic> json) {
    return OutstandingDay(
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

/// Satu transaksi tunai di dalam [OutstandingDay] — untuk mencocokkan uang
/// fisik. Sumber: GET /cso/deposits/outstanding/{date}
class OutstandingTx {
  final int id;
  final int? bookingId;
  final num amount;
  final DateTime? createdAt;
  final String? destination;
  final String? driver;

  const OutstandingTx({
    required this.id,
    required this.bookingId,
    required this.amount,
    required this.createdAt,
    required this.destination,
    required this.driver,
  });

  factory OutstandingTx.fromJson(Map<String, dynamic> json) {
    return OutstandingTx(
      id: int.tryParse('${json['id']}') ?? 0,
      bookingId: int.tryParse('${json['booking_id']}'),
      amount: num.tryParse('${json['amount']}') ?? 0,
      createdAt: DateTime.tryParse('${json['created_at'] ?? ''}')?.toLocal(),
      destination: json['destination']?.toString(),
      driver: json['driver']?.toString(),
    );
  }
}

/// Satu pengajuan setoran.
/// Sumber: GET /cso/deposits, POST /cso/deposits
class CsoDeposit {
  final int id;
  final num amount;

  /// Pending | Approved | Rejected
  final String status;

  /// Tanggal-tanggal yang tercakup ('YYYY-MM-DD').
  final List<String> periodDates;
  final int transactionsCount;
  final String? note;

  /// Alasan penolakan / catatan admin — inilah yang perlu dibaca CSO saat ditolak.
  final String? adminNote;
  final DateTime? submittedAt;
  final DateTime? processedAt;

  /// Path foto bukti di disk `public` server (sudah berstempel), atau null.
  final String? proofImage;

  const CsoDeposit({
    required this.id,
    required this.amount,
    required this.status,
    required this.periodDates,
    required this.transactionsCount,
    this.note,
    this.adminNote,
    this.submittedAt,
    this.processedAt,
    this.proofImage,
  });

  factory CsoDeposit.fromJson(Map<String, dynamic> json) {
    num toNum(dynamic v) =>
        v is num ? v : (num.tryParse(v?.toString() ?? '0') ?? 0);

    // period_dates bisa datang sebagai List (JSON cast Laravel) atau null.
    final rawDates = json['period_dates'];
    final dates = rawDates is List
        ? rawDates.map((e) => e.toString()).toList()
        : <String>[];

    return CsoDeposit(
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
      submittedAt: DateTime.tryParse(json['submitted_at']?.toString() ?? '')
          ?.toLocal(),
      processedAt: DateTime.tryParse(json['processed_at']?.toString() ?? '')
          ?.toLocal(),
      proofImage: (json['proof_image']?.toString().trim().isEmpty ?? true)
          ? null
          : json['proof_image'].toString(),
    );
  }

  bool get isPending => status == 'Pending';
  bool get isApproved => status == 'Approved';
  bool get isRejected => status == 'Rejected';
}
