import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart' show rootBundle;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'api_service.dart';
import '../utils/format.dart';

/// Membuat & membagikan karcis dalam bentuk PDF (untuk dikirim ke WhatsApp
/// penumpang atau dicetak/disimpan). Cetak thermal (RawBT) sengaja dilewati
/// sesuai keputusan; PDF + share menggantikannya dengan UX yang lebih mulus.
///
/// PDF ini dilihat penumpang di ponsel, jadi bentuknya kartu karcis berwarna
/// (lebar 90 mm), bukan struk thermal 58 mm: yang dicari penumpang di
/// parkiran adalah PLAT & NOMOR LAMBUNG mobil, lalu status bayarnya.
class ReceiptService {
  /// URL halaman struk publik (untuk QR code & verifikasi online).
  static String publicUrlFor(Map<String, dynamic> booking) {
    final f = _Fields.from(booking);
    return f.publicUrl;
  }

  /// Bagikan karcis PDF via share sheet (pilih WhatsApp → PDF otomatis terlampir).
  static Future<void> sharePdf(Map<String, dynamic> booking) async {
    final f = _Fields.from(booking);
    final bytes = await _build(f);
    await Printing.sharePdf(bytes: bytes, filename: 'Karcis_${f.code}.pdf');
  }

  /// Buka dialog cetak/simpan sistem (cetak ke printer mana pun atau simpan PDF).
  static Future<void> printPdf(Map<String, dynamic> booking) async {
    final f = _Fields.from(booking);
    final bytes = await _build(f);
    await Printing.layoutPdf(
      name: 'Karcis_${f.code}',
      onLayout: (_) async => bytes,
    );
  }

  @visibleForTesting
  static Future<Uint8List> buildPdf(Map<String, dynamic> booking) =>
      _build(_Fields.from(booking));

  // === Palet (selaras AppColors aplikasi) ===================================

  static const _deepBlue = PdfColor.fromInt(0xFF0B4DA2);
  static const _skyBlue = PdfColor.fromInt(0xFF1E88E5);
  static const _paleBlue = PdfColor.fromInt(0xFFEAF3FF);
  static const _ink = PdfColor.fromInt(0xFF0D2138);
  static const _inkSoft = PdfColor.fromInt(0xFF5E708A);
  static const _inkFaint = PdfColor.fromInt(0xFF9AAAC0);
  static const _line = PdfColor.fromInt(0xFFDCE6F2);
  static const _success = PdfColor.fromInt(0xFF18B27E);
  static const _successPale = PdfColor.fromInt(0xFFE3F6EF);
  static const _orange = PdfColor.fromInt(0xFFF57C00);
  static const _orangePale = PdfColor.fromInt(0xFFFFF1E0);
  static const _red = PdfColor.fromInt(0xFFE53935);

  static Future<pw.MemoryImage?> _asset(String path) async {
    try {
      final data = await rootBundle.load(path);
      return pw.MemoryImage(data.buffer.asUint8List());
    } catch (_) {
      return null; // logo hilang tidak boleh menggagalkan karcis
    }
  }

  static Future<Uint8List> _build(_Fields f) async {
    final logo = await _asset('assets/images/logo_taksi.png');
    final doc = pw.Document(
      title: 'Karcis Taksi ${f.code}',
      author: 'Koperasi Angkasa Jaya',
    );
    final theme = pw.ThemeData.withFont(
      base: pw.Font.helvetica(),
      bold: pw.Font.helveticaBold(),
    );

    doc.addPage(
      pw.Page(
        theme: theme,
        pageFormat: const PdfPageFormat(
          90 * PdfPageFormat.mm,
          double.infinity,
          marginAll: 0,
        ),
        build: (context) => pw.Container(
          color: _paleBlue,
          padding: const pw.EdgeInsets.fromLTRB(10, 10, 10, 0),
          child: pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.stretch,
            children: [
              pw.Container(
                decoration: pw.BoxDecoration(
                  color: PdfColors.white,
                  borderRadius: pw.BorderRadius.circular(14),
                ),
                child: pw.Column(
                  crossAxisAlignment: pw.CrossAxisAlignment.stretch,
                  children: [
                    _header(f, logo),
                    pw.Padding(
                      padding: const pw.EdgeInsets.fromLTRB(16, 14, 16, 0),
                      child: pw.Column(
                        crossAxisAlignment: pw.CrossAxisAlignment.stretch,
                        children: [
                          _paymentBanner(f),
                          pw.SizedBox(height: 12),
                          _route(f),
                          pw.SizedBox(height: 12),
                          _taxiBox(f),
                          pw.SizedBox(height: 12),
                          _details(f),
                        ],
                      ),
                    ),
                    pw.SizedBox(height: 14),
                    _tearLine(),
                    _stub(f),
                  ],
                ),
              ),
              _footer(),
            ],
          ),
        ),
      ),
    );
    return doc.save();
  }

  // === Bagian-bagian karcis =================================================

  static pw.Widget _header(_Fields f, pw.MemoryImage? logo) {
    return pw.Container(
      padding: const pw.EdgeInsets.fromLTRB(16, 16, 16, 16),
      decoration: const pw.BoxDecoration(
        borderRadius: pw.BorderRadius.vertical(top: pw.Radius.circular(14)),
        gradient: pw.LinearGradient(
          colors: [_deepBlue, _skyBlue],
          begin: pw.Alignment.topLeft,
          end: pw.Alignment.bottomRight,
        ),
      ),
      child: pw.Row(
        children: [
          pw.Container(
            width: 46,
            height: 46,
            padding: const pw.EdgeInsets.all(5),
            decoration: pw.BoxDecoration(
              color: PdfColors.white,
              borderRadius: pw.BorderRadius.circular(10),
            ),
            child: logo == null
                ? pw.SizedBox()
                : pw.Image(logo, fit: pw.BoxFit.contain),
          ),
          pw.SizedBox(width: 10),
          pw.Expanded(
            child: pw.Column(
              crossAxisAlignment: pw.CrossAxisAlignment.start,
              children: [
                pw.Text(
                  'KARCIS TAKSI BANDARA',
                  style: pw.TextStyle(
                    color: PdfColors.white,
                    fontWeight: pw.FontWeight.bold,
                    fontSize: 12.5,
                    letterSpacing: 0.6,
                  ),
                ),
                pw.SizedBox(height: 2),
                pw.Text(
                  'Koperasi Angkasa Jaya',
                  style: const pw.TextStyle(
                    color: PdfColors.white,
                    fontSize: 8.5,
                  ),
                ),
                pw.Text(
                  'Bandar Udara APT. Pranoto Samarinda',
                  style: const pw.TextStyle(
                    color: PdfColor.fromInt(0xFFD6E6FB),
                    fontSize: 7.5,
                  ),
                ),
              ],
            ),
          ),
          pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.end,
            children: [
              pw.Text(
                'NO. KARCIS',
                style: const pw.TextStyle(
                  color: PdfColor.fromInt(0xFFD6E6FB),
                  fontSize: 6.5,
                ),
              ),
              pw.Text(
                '#${f.code}',
                style: pw.TextStyle(
                  color: PdfColors.white,
                  fontWeight: pw.FontWeight.bold,
                  fontSize: 13,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Status bayar paling atas: penumpang tunai-ke-supir harus tahu sejak awal
  /// bahwa ia masih harus membayar; penumpang lain tahu ia tidak perlu bayar lagi.
  static pw.Widget _paymentBanner(_Fields f) {
    final tunaiSupir = f.method == 'CashDriver';
    final warna = tunaiSupir ? _orange : _success;
    final latar = tunaiSupir ? _orangePale : _successPale;
    final judul = tunaiSupir ? 'BAYAR TUNAI KE SUPIR' : 'LUNAS';
    final ket = tunaiSupir
        ? 'Bayar tarif ini langsung kepada supir.'
        : 'Dibayar via ${f.methodLabel}. Tidak perlu membayar lagi.';

    return pw.Container(
      padding: const pw.EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: pw.BoxDecoration(
        color: latar,
        borderRadius: pw.BorderRadius.circular(10),
        border: pw.Border.all(color: warna, width: 1),
      ),
      child: pw.Row(
        crossAxisAlignment: pw.CrossAxisAlignment.center,
        children: [
          pw.Expanded(
            child: pw.Column(
              crossAxisAlignment: pw.CrossAxisAlignment.start,
              children: [
                pw.Text(
                  judul,
                  style: pw.TextStyle(
                    color: warna,
                    fontWeight: pw.FontWeight.bold,
                    fontSize: 10.5,
                    letterSpacing: 0.4,
                  ),
                ),
                pw.SizedBox(height: 2),
                pw.Text(
                  ket,
                  style: const pw.TextStyle(color: _inkSoft, fontSize: 7.5),
                ),
              ],
            ),
          ),
          pw.SizedBox(width: 8),
          pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.end,
            children: [
              pw.Text(
                'TARIF',
                style: const pw.TextStyle(color: _inkSoft, fontSize: 6.5),
              ),
              pw.Text(
                formatRupiah(f.amount),
                style: pw.TextStyle(
                  color: tunaiSupir ? _red : _ink,
                  fontWeight: pw.FontWeight.bold,
                  fontSize: 16,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  static pw.Widget _route(_Fields f) {
    pw.Widget dot(PdfColor c) => pw.Container(
      width: 9,
      height: 9,
      decoration: pw.BoxDecoration(
        color: PdfColors.white,
        shape: pw.BoxShape.circle,
        border: pw.Border.all(color: c, width: 2.2),
      ),
    );

    return pw.Container(
      padding: const pw.EdgeInsets.all(12),
      decoration: pw.BoxDecoration(
        color: _paleBlue,
        borderRadius: pw.BorderRadius.circular(10),
      ),
      child: pw.Row(
        children: [
          pw.Column(
            children: [
              dot(_success),
              ...List.generate(
                4,
                (_) => pw.Container(
                  width: 1.2,
                  height: 3.5,
                  margin: const pw.EdgeInsets.symmetric(vertical: 1.5),
                  color: _inkFaint,
                ),
              ),
              dot(_red),
            ],
          ),
          pw.SizedBox(width: 10),
          pw.Expanded(
            child: pw.Column(
              crossAxisAlignment: pw.CrossAxisAlignment.start,
              children: [
                _label('DARI'),
                pw.Text(
                  'Bandara APT. Pranoto',
                  style: const pw.TextStyle(color: _ink, fontSize: 9),
                ),
                pw.SizedBox(height: 8),
                _label('TUJUAN'),
                pw.Text(
                  f.zoneName,
                  style: pw.TextStyle(
                    color: _ink,
                    fontWeight: pw.FontWeight.bold,
                    fontSize: 13,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Plat & nomor lambung dibuat besar: itulah yang dicocokkan penumpang
  /// dengan mobil di jalur jemput.
  static pw.Widget _taxiBox(_Fields f) {
    pw.Widget kolom(String label, String nilai, {double size = 13}) =>
        pw.Expanded(
          child: pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.start,
            children: [
              _label(label),
              pw.SizedBox(height: 1),
              pw.Text(
                nilai,
                style: pw.TextStyle(
                  color: _ink,
                  fontWeight: pw.FontWeight.bold,
                  fontSize: size,
                ),
              ),
            ],
          ),
        );

    return pw.Container(
      padding: const pw.EdgeInsets.all(12),
      decoration: pw.BoxDecoration(
        borderRadius: pw.BorderRadius.circular(10),
        border: pw.Border.all(color: _line, width: 1),
      ),
      child: pw.Column(
        crossAxisAlignment: pw.CrossAxisAlignment.stretch,
        children: [
          pw.Row(
            children: [
              kolom('PLAT NOMOR', f.plate, size: 15),
              pw.SizedBox(width: 8),
              kolom('NO. LAMBUNG', f.line, size: 15),
            ],
          ),
          pw.SizedBox(height: 8),
          pw.Container(height: 0.8, color: _line),
          pw.SizedBox(height: 8),
          pw.Row(
            children: [
              kolom('SUPIR', f.driverName, size: 9.5),
              pw.SizedBox(width: 8),
              kolom('KENDARAAN', f.carModel, size: 9.5),
            ],
          ),
        ],
      ),
    );
  }

  static pw.Widget _details(_Fields f) {
    return pw.Column(
      children: [
        _kv('Waktu terbit', f.dateTimeStr),
        _kv('Kasir (CSO)', f.kasir),
        _kv('Metode bayar', f.methodLabel),
        if (f.passengerPhone != null) _kv('Penumpang', f.passengerPhone!),
      ],
    );
  }

  /// Garis sobek dengan "takik" setengah lingkaran di kedua sisi.
  static pw.Widget _tearLine() {
    return pw.SizedBox(
      height: 16,
      child: pw.Stack(
        alignment: pw.Alignment.center,
        overflow: pw.Overflow.visible,
        children: [
          pw.Padding(
            padding: const pw.EdgeInsets.symmetric(horizontal: 14),
            child: pw.Row(
              mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
              children: List.generate(
                28,
                (_) => pw.Container(width: 4, height: 1, color: _inkFaint),
              ),
            ),
          ),
          pw.Positioned(
            left: -8,
            child: pw.Container(
              width: 16,
              height: 16,
              decoration: const pw.BoxDecoration(
                color: _paleBlue,
                shape: pw.BoxShape.circle,
              ),
            ),
          ),
          pw.Positioned(
            right: -8,
            child: pw.Container(
              width: 16,
              height: 16,
              decoration: const pw.BoxDecoration(
                color: _paleBlue,
                shape: pw.BoxShape.circle,
              ),
            ),
          ),
        ],
      ),
    );
  }

  static pw.Widget _stub(_Fields f) {
    return pw.Padding(
      padding: const pw.EdgeInsets.fromLTRB(16, 8, 16, 12),
      child: pw.Row(
        crossAxisAlignment: pw.CrossAxisAlignment.center,
        children: [
          pw.Container(
            padding: const pw.EdgeInsets.all(4),
            decoration: pw.BoxDecoration(
              borderRadius: pw.BorderRadius.circular(8),
              border: pw.Border.all(color: _line, width: 1),
            ),
            child: pw.BarcodeWidget(
              barcode: pw.Barcode.qrCode(),
              data: f.publicUrl,
              width: 74,
              height: 74,
              drawText: false,
              color: _ink,
            ),
          ),
          pw.SizedBox(width: 12),
          pw.Expanded(
            child: pw.Column(
              crossAxisAlignment: pw.CrossAxisAlignment.start,
              children: [
                pw.Text(
                  'Cek keaslian karcis',
                  style: pw.TextStyle(
                    color: _ink,
                    fontWeight: pw.FontWeight.bold,
                    fontSize: 10,
                  ),
                ),
                pw.SizedBox(height: 3),
                pw.Text(
                  'Pindai kode QR untuk membuka karcis resmi ini secara online.',
                  style: const pw.TextStyle(color: _inkSoft, fontSize: 7.5),
                ),
                pw.SizedBox(height: 6),
                pw.Container(
                  padding: const pw.EdgeInsets.symmetric(
                    horizontal: 6,
                    vertical: 3,
                  ),
                  decoration: pw.BoxDecoration(
                    color: _paleBlue,
                    borderRadius: pw.BorderRadius.circular(6),
                  ),
                  child: pw.Text(
                    'No. karcis #${f.code}',
                    style: pw.TextStyle(
                      color: _deepBlue,
                      fontWeight: pw.FontWeight.bold,
                      fontSize: 7,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  static pw.Widget _footer() {
    return pw.Padding(
      padding: const pw.EdgeInsets.fromLTRB(8, 9, 8, 12),
      child: pw.Text(
        'Simpan karcis ini sebagai bukti perjalanan. Terima kasih telah '
        'menggunakan Taksi Angkasa Jaya - selamat jalan!',
        textAlign: pw.TextAlign.center,
        style: const pw.TextStyle(color: _inkSoft, fontSize: 7),
      ),
    );
  }

  static pw.Widget _label(String text) => pw.Text(
    text,
    style: pw.TextStyle(
      color: _inkFaint,
      fontWeight: pw.FontWeight.bold,
      fontSize: 6.5,
      letterSpacing: 0.6,
    ),
  );

  static pw.Widget _kv(String k, String v) {
    return pw.Padding(
      padding: const pw.EdgeInsets.symmetric(vertical: 2),
      child: pw.Row(
        crossAxisAlignment: pw.CrossAxisAlignment.start,
        children: [
          pw.SizedBox(
            width: 70,
            child: pw.Text(
              k,
              style: const pw.TextStyle(color: _inkSoft, fontSize: 8),
            ),
          ),
          pw.Expanded(
            child: pw.Text(
              v,
              textAlign: pw.TextAlign.right,
              style: pw.TextStyle(
                color: _ink,
                fontSize: 8,
                fontWeight: pw.FontWeight.bold,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Field yang sudah diolah dari data booking/transaksi untuk dipakai di PDF.
class _Fields {
  final String code;
  final String zoneName;
  final String driverName;
  final String line;
  final String plate;
  final String carModel;
  final String kasir;
  final String method;
  final String methodLabel;
  final String dateTimeStr;
  final String? passengerPhone;
  final num amount;
  final String publicUrl;

  _Fields({
    required this.code,
    required this.zoneName,
    required this.driverName,
    required this.line,
    required this.plate,
    required this.carModel,
    required this.kasir,
    required this.method,
    required this.methodLabel,
    required this.dateTimeStr,
    required this.passengerPhone,
    required this.amount,
    required this.publicUrl,
  });

  factory _Fields.from(Map<String, dynamic> booking) {
    Map<String, dynamic> m(dynamic v) =>
        (v as Map?)?.cast<String, dynamic>() ?? const {};
    final driver = m(booking['driver']);
    final profile = m(driver['driver_profile']);
    final zone = m(booking['zone_to']);
    final cso = m(booking['cso']);
    final tx = m(booking['transaction']);

    String s(dynamic v, [String fb = '-']) =>
        (v == null || v.toString().trim().isEmpty) ? fb : v.toString().trim();
    final method = s(tx['method'], 'QRIS');
    final methodLabel = method == 'CashDriver'
        ? 'Tunai ke Supir'
        : (method == 'CashCSO' ? 'Tunai di Kasir CSO' : 'QRIS');
    final amount = booking['price'] is num
        ? booking['price'] as num
        : num.tryParse('${booking['price'] ?? tx['amount'] ?? 0}') ?? 0;
    final code = s(tx['id'] ?? booking['id']);
    final dt = DateTime.tryParse(
      '${tx['created_at'] ?? booking['created_at'] ?? ''}',
    )?.toLocal();
    String two(int n) => n.toString().padLeft(2, '0');
    final dateTimeStr = dt == null
        ? '-'
        : '${two(dt.day)}/${two(dt.month)}/${dt.year} ${two(dt.hour)}:${two(dt.minute)}';

    // Nomor penumpang disamarkan: PDF ini bisa diteruskan ke siapa saja.
    final phone = s(booking['passenger_phone'], '');
    final digits = phone.replaceAll(RegExp(r'\D'), '');
    final maskedPhone = digits.length < 7
        ? null
        : '${digits.substring(0, 4)}****${digits.substring(digits.length - 3)}';

    return _Fields(
      code: code,
      zoneName: s(zone['name'], s(booking['manual_destination'])),
      driverName: s(driver['name']),
      line: profile['line_number'] == null ? '-' : 'L${profile['line_number']}',
      plate: s(profile['plate_number']).toUpperCase(),
      carModel: s(profile['car_model']),
      kasir: s(cso['name']).split(' ').first,
      method: method,
      methodLabel: methodLabel,
      dateTimeStr: dateTimeStr,
      passengerPhone: maskedPhone,
      amount: amount,
      publicUrl:
          '${ApiService.assetBaseUrl}/receipt/${s(tx['receipt_token'], code)}',
    );
  }
}
