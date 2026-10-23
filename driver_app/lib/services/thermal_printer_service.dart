import 'package:esc_pos_utils_plus/esc_pos_utils_plus.dart';
import 'package:flutter/services.dart' show rootBundle;
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:image/image.dart' as img;
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';
import 'api_service.dart';
import '../utils/format.dart';

/// Kesalahan cetak yang aman ditampilkan ke CSO.
class PrinterException implements Exception {
  final String message;
  PrinterException(this.message);
  @override
  String toString() => message;
}

/// Cetak karcis ke printer thermal Bluetooth 58 mm (ESC/POS), mis. iWare.
///
/// Alur: printer di-*pair* dulu di Pengaturan Bluetooth Android → CSO memilihnya
/// sekali di app (MAC disimpan) → tombol "Cetak Karcis" menyambung & mencetak.
class ThermalPrinterService {
  static const _storage = FlutterSecureStorage();
  static const _keyMac = 'printer_mac';
  static const _keyName = 'printer_name';

  static Future<bool> btEnabled() => PrintBluetoothThermal.bluetoothEnabled;
  static Future<bool> isConnected() => PrintBluetoothThermal.connectionStatus;

  /// Perangkat yang SUDAH di-pair di Pengaturan Bluetooth Android.
  static Future<List<BluetoothInfo>> pairedDevices() =>
      PrintBluetoothThermal.pairedBluetooths;

  static Future<String?> savedMac() => _storage.read(key: _keyMac);
  static Future<String?> savedName() => _storage.read(key: _keyName);

  static Future<void> savePrinter(String mac, String name) async {
    await _storage.write(key: _keyMac, value: mac);
    await _storage.write(key: _keyName, value: name);
  }

  static Future<bool> connect(String mac) async {
    if (await PrintBluetoothThermal.connectionStatus) return true;
    return PrintBluetoothThermal.connect(macPrinterAddress: mac);
  }

  static Future<void> disconnect() => PrintBluetoothThermal.disconnect;

  /// Pastikan tersambung ke printer tersimpan lalu cetak karcis dari [booking].
  /// Melempar [PrinterException] berisi pesan ramah bila gagal.
  static Future<void> printReceipt(Map<String, dynamic> booking) async {
    await _send(await _buildTicket(booking));
  }

  /// Cetak karcis contoh (data rekaan, bertanda TES PRINT) untuk mengecek
  /// printer, kertas & tampilan tanpa membuat transaksi. [csoName] = kasir.
  static Future<void> printTest({String? csoName}) async {
    final now = DateTime.now().toUtc().toIso8601String();
    await _send(await _buildTicket({
      'id': 0,
      'price': 150000,
      'driver': {
        'name': 'Contoh Supir',
      },
      'zone_to': {'name': 'Samarinda Kota'},
      'cso': {'name': csoName ?? 'Kasir'},
      'transaction': {
        'id': 'TES',
        'method': 'QRIS',
        'created_at': now,
        'receipt_token': 'tes-print',
      },
    }, test: true));
  }

  static Future<void> _send(List<int> bytes) async {
    final mac = await savedMac();
    if (mac == null || mac.isEmpty) {
      throw PrinterException('Belum ada printer dipilih. Pilih printer dulu.');
    }
    if (!await PrintBluetoothThermal.bluetoothEnabled) {
      throw PrinterException('Bluetooth mati. Nyalakan Bluetooth dulu.');
    }
    if (!await PrintBluetoothThermal.connectionStatus) {
      final ok = await PrintBluetoothThermal.connect(macPrinterAddress: mac);
      if (!ok) {
        throw PrinterException(
            'Gagal menyambung ke printer. Pastikan printer menyala & dekat.');
      }
    }
    final printed = await PrintBluetoothThermal.writeBytes(bytes);
    if (!printed) {
      throw PrinterException('Gagal mengirim data ke printer. Coba lagi.');
    }
  }

  /// Lebar kertas 58 mm = 384 dot = 32 karakter Font A per baris. Semua baris
  /// dirakit manual (padding spasi) supaya rapi di printer murah sekalipun —
  /// tidak bergantung pada perintah posisi kolom ESC/POS.
  static const _cols = 32;

  static Future<List<int>> _buildTicket(Map<String, dynamic> booking,
      {bool test = false}) async {
    final f = _TicketData.from(booking);
    final profile = await CapabilityProfile.load();
    final g = Generator(PaperSize.mm58, profile);
    List<int> b = g.reset();

    // ── Kop ──────────────────────────────────────────
    final logo = await _loadLogo();
    if (logo != null) {
      b += g.image(logo);
    }
    b += g.text('KOPERASI ANGKASA JAYA',
        styles: const PosStyles(
            align: PosAlign.center, bold: true, height: PosTextSize.size2));
    b += g.text('Bandara APT Pranoto Samarinda',
        styles: const PosStyles(align: PosAlign.center));
    b += g.feed(1);
    if (test) {
      b += _center(g, '*** TES PRINT ***', bold: true, tall: true);
    }
    b += _kv(g, 'No.', '#${f.code}', bold: true);
    b += g.hr(ch: '=');

    // ── Rincian ──────────────────────────────────────
    b += _kv(g, 'Tanggal', f.dateStr);
    b += _kv(g, 'Jam', f.timeStr);
    b += _kv(g, 'Supir', f.driverName);
    b += _kv(g, 'Kasir', f.kasir);
    b += _kv(g, 'Bayar', f.methodLabel);
    b += g.hr(ch: '.');
    b += _center(g, 'TUJUAN');
    b += _center(g, f.zoneName.toUpperCase(), bold: true, tall: true);
    b += g.hr(ch: '=');

    // ── Total ────────────────────────────────────────
    // Muat 16 karakter lebar ganda → cetak besar; nominal jutaan → tinggi saja.
    final wide = 'TOTAL'.length + 1 + f.amountStr.length <= _cols ~/ 2;
    final w = wide ? _cols ~/ 2 : _cols;
    b += g.text('TOTAL${f.amountStr.padLeft(w - 5)}',
        styles: PosStyles(
            bold: true,
            height: PosTextSize.size2,
            width: wide ? PosTextSize.size2 : PosTextSize.size1));
    b += g.hr(ch: '=');
    b += g.feed(1);

    // ── QR & penutup ─────────────────────────────────
    if (test) {
      b += _center(g, 'BUKAN BUKTI PEMBAYARAN', bold: true);
      b += g.feed(1);
    }
    b += g.qrcode(f.publicUrl, size: QRSize.size6);
    b += _center(g, 'Scan untuk cek struk online');
    b += g.hr();
    b += _center(g, 'Terima kasih & selamat jalan', bold: true);
    b += _center(g, 'Simpan karcis sbg bukti bayar');
    b += g.feed(3);
    b += g.cut();
    return b;
  }

  static img.Image? _cachedLogo;

  /// Muat logo koperasi dari asset, resize untuk kertas 58mm, jadikan hitam-putih
  /// (thermal hanya monokrom). Gagal muat → null (cetak tanpa logo, tak crash).
  static Future<img.Image?> _loadLogo() async {
    if (_cachedLogo != null) return _cachedLogo;
    try {
      final data = await rootBundle.load('assets/images/logo-koperasi.png');
      var image = img.decodeImage(data.buffer.asUint8List());
      if (image == null) return null;
      image = img.copyResize(image, width: 240);
      image = img.grayscale(image);
      _cachedLogo = image;
      return image;
    } catch (_) {
      return null;
    }
  }

  /// Baris "label ........ nilai": label rata kiri, nilai rata kanan; nilai
  /// panjang dibungkus ke baris berikut tetap rata kanan.
  static List<int> _kv(Generator g, String k, String v, {bool bold = false}) {
    const labelW = 8;
    final lines = _wrap(v, _cols - labelW);
    List<int> b = [];
    for (var i = 0; i < lines.length; i++) {
      b += g.text((i == 0 ? k : '').padRight(labelW) + lines[i].padLeft(_cols - labelW),
          styles: PosStyles(bold: bold));
    }
    return b;
  }

  /// Teks rata tengah yang dibungkus per kata sesuai lebar efektif
  /// ([wide] = huruf lebar ganda → 16 karakter per baris).
  static List<int> _center(Generator g, String text,
      {bool bold = false, bool tall = false, bool wide = false}) {
    List<int> b = [];
    for (final line in _wrap(text, wide ? _cols ~/ 2 : _cols)) {
      b += g.text(line,
          styles: PosStyles(
              align: PosAlign.center,
              bold: bold,
              height: tall ? PosTextSize.size2 : PosTextSize.size1,
              width: wide ? PosTextSize.size2 : PosTextSize.size1));
    }
    return b;
  }

  /// Bungkus per kata ke baris selebar [width]; kata kepanjangan dipotong paksa.
  static List<String> _wrap(String s, int width) {
    final lines = <String>[];
    var cur = '';
    for (var word in s.split(RegExp(r'\s+'))) {
      if (word.isEmpty) continue;
      while (word.length > width) {
        if (cur.isNotEmpty) {
          lines.add(cur);
          cur = '';
        }
        lines.add(word.substring(0, width));
        word = word.substring(width);
      }
      if (cur.isEmpty) {
        cur = word;
      } else if (cur.length + 1 + word.length <= width) {
        cur += ' $word';
      } else {
        lines.add(cur);
        cur = word;
      }
    }
    if (cur.isNotEmpty || lines.isEmpty) lines.add(cur);
    return lines;
  }
}

/// Field karcis yang diolah dari data booking/transaksi.
class _TicketData {
  final String code;
  final String zoneName;
  final String kasir;
  final String methodLabel;
  final String dateStr;
  final String timeStr;
  final String amountStr;
  final String publicUrl;
  final String driverName;

  _TicketData({
    required this.code,
    required this.zoneName,
    required this.kasir,
    required this.methodLabel,
    required this.dateStr,
    required this.timeStr,
    required this.amountStr,
    required this.publicUrl,
    required this.driverName,
  });

  factory _TicketData.from(Map<String, dynamic> booking) {
    Map<String, dynamic> m(dynamic v) =>
        (v as Map?)?.cast<String, dynamic>() ?? const {};
    final driver = m(booking['driver']);
    final zone = m(booking['zone_to']);
    final cso = m(booking['cso']);
    final tx = m(booking['transaction']);

    // Printer thermal hanya paham ASCII; huruf lain (é, emoji) diganti '?'
    // supaya encoder tak gagal di tengah cetak.
    String s(dynamic v, [String fb = '-']) => (v == null || v.toString().isEmpty)
        ? fb
        : v.toString().replaceAll(RegExp(r'[^ -~]'), '?');

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

    return _TicketData(
      code: code,
      zoneName: s(zone['name'], s(booking['manual_destination'])),
      kasir: s(cso['name']).split(' ').first,
      methodLabel: methodLabel,
      dateStr: dt == null ? '-' : '${two(dt.day)}/${two(dt.month)}/${dt.year}',
      timeStr: dt == null ? '-' : '${two(dt.hour)}:${two(dt.minute)} WITA',
      amountStr: formatRupiah(amount),
      publicUrl: '${ApiService.assetBaseUrl}/receipt/${s(tx['receipt_token'], code)}',
      driverName: s(driver['name']),
    );
  }
}
