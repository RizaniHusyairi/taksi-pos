import 'dart:typed_data';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'api_service.dart';
import '../utils/format.dart';

/// Membuat & membagikan struk dalam bentuk PDF (untuk dikirim ke WhatsApp
/// penumpang atau dicetak/disimpan). Cetak thermal (RawBT) sengaja dilewati
/// sesuai keputusan; PDF + share menggantikannya dengan UX yang lebih mulus.
class ReceiptService {
  /// URL halaman struk publik (untuk QR code & verifikasi online).
  static String publicUrlFor(Map<String, dynamic> booking) {
    final f = _Fields.from(booking);
    return f.publicUrl;
  }

  /// Bagikan struk PDF via share sheet (pilih WhatsApp → PDF otomatis terlampir).
  static Future<void> sharePdf(Map<String, dynamic> booking) async {
    final f = _Fields.from(booking);
    final bytes = await _build(f);
    await Printing.sharePdf(bytes: bytes, filename: 'Struk_${f.code}.pdf');
  }

  /// Buka dialog cetak/simpan sistem (cetak ke printer mana pun atau simpan PDF).
  static Future<void> printPdf(Map<String, dynamic> booking) async {
    final f = _Fields.from(booking);
    final bytes = await _build(f);
    await Printing.layoutPdf(
      name: 'Struk_${f.code}',
      onLayout: (_) async => bytes,
    );
  }

  static Future<Uint8List> _build(_Fields f) async {
    final doc = pw.Document();
    final theme = pw.ThemeData.withFont(
      base: pw.Font.courier(),
      bold: pw.Font.courierBold(),
    );

    doc.addPage(
      pw.Page(
        theme: theme,
        pageFormat: PdfPageFormat(
          58 * PdfPageFormat.mm,
          double.infinity,
          marginAll: 4 * PdfPageFormat.mm,
        ),
        build: (context) => pw.Column(
          crossAxisAlignment: pw.CrossAxisAlignment.stretch,
          children: [
            pw.Center(
              child: pw.Text(
                'KOPERASI ANGKASA JAYA',
                style: pw.TextStyle(
                  fontWeight: pw.FontWeight.bold,
                  fontSize: 11,
                ),
              ),
            ),
            pw.Center(
              child: pw.Text(
                'Bandar Udara APT. Pranoto Samarinda',
                style: const pw.TextStyle(fontSize: 7),
              ),
            ),
            pw.SizedBox(height: 8),
            _kv('Waktu', f.dateTimeStr),
            _kv('Kasir', f.kasir),
            _kv('Supir', f.driverDisplay),
            _kv('No.', f.code),
            pw.Divider(height: 8),
            _kv('Tujuan', f.zoneName),
            _kv('Metode', f.methodLabel),
            pw.Divider(height: 8),
            pw.Row(
              mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
              children: [
                pw.Text(
                  'TOTAL',
                  style: pw.TextStyle(fontWeight: pw.FontWeight.bold),
                ),
                pw.Text(
                  formatRupiah(f.amount),
                  style: pw.TextStyle(
                    fontWeight: pw.FontWeight.bold,
                    fontSize: 11,
                  ),
                ),
              ],
            ),
            pw.SizedBox(height: 10),
            pw.Center(
              child: pw.BarcodeWidget(
                barcode: pw.Barcode.qrCode(),
                data: f.publicUrl,
                width: 90,
                height: 90,
                drawText: false,
              ),
            ),
            pw.SizedBox(height: 4),
            pw.Center(
              child: pw.Text(
                'Scan untuk cek struk online',
                style: const pw.TextStyle(fontSize: 6),
              ),
            ),
            pw.SizedBox(height: 6),
            pw.Center(
              child: pw.Text(
                'Simpan struk ini sebagai bukti.',
                style: const pw.TextStyle(fontSize: 6),
              ),
            ),
          ],
        ),
      ),
    );
    return doc.save();
  }

  static pw.Widget _kv(String k, String v) {
    return pw.Padding(
      padding: const pw.EdgeInsets.symmetric(vertical: 1),
      child: pw.Row(
        crossAxisAlignment: pw.CrossAxisAlignment.start,
        children: [
          pw.SizedBox(
            width: 46,
            child: pw.Text(k, style: const pw.TextStyle(fontSize: 8)),
          ),
          pw.Expanded(
            child: pw.Text(
              v,
              textAlign: pw.TextAlign.right,
              style: pw.TextStyle(fontSize: 8, fontWeight: pw.FontWeight.bold),
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
  final String driverDisplay;
  final String kasir;
  final String methodLabel;
  final String dateTimeStr;
  final num amount;
  final String publicUrl;

  _Fields({
    required this.code,
    required this.zoneName,
    required this.driverDisplay,
    required this.kasir,
    required this.methodLabel,
    required this.dateTimeStr,
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
        (v == null || v.toString().isEmpty) ? fb : v.toString();
    final line = profile['line_number'] == null
        ? ''
        : ' #L${profile['line_number']}';
    final method = s(tx['method'], 'QRIS');
    final methodLabel = method == 'CashDriver'
        ? 'Tunai (Supir)'
        : (method == 'CashCSO' ? 'Tunai (Kasir)' : 'QRIS');
    final amount = booking['price'] is num
        ? booking['price'] as num
        : num.tryParse('${booking['price'] ?? tx['amount'] ?? 0}') ?? 0;
    final code = s(tx['id'] ?? booking['id']);
    final dt = DateTime.tryParse('${tx['created_at'] ?? ''}')?.toLocal();
    String two(int n) => n.toString().padLeft(2, '0');
    final dateTimeStr = dt == null
        ? '-'
        : '${two(dt.day)}/${two(dt.month)}/${dt.year} ${two(dt.hour)}:${two(dt.minute)}';

    return _Fields(
      code: code,
      zoneName: s(zone['name']),
      driverDisplay: '${s(driver['name'])}$line',
      kasir: s(cso['name']).split(' ').first,
      methodLabel: methodLabel,
      dateTimeStr: dateTimeStr,
      amount: amount,
      publicUrl: '${ApiService.assetBaseUrl}/receipt/${s(tx['receipt_token'], code)}',
    );
  }
}
