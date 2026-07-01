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
    final bytes = await _buildTicket(booking);
    final printed = await PrintBluetoothThermal.writeBytes(bytes);
    if (!printed) {
      throw PrinterException('Gagal mengirim data ke printer. Coba lagi.');
    }
  }

  static Future<List<int>> _buildTicket(Map<String, dynamic> booking) async {
    final f = _TicketData.from(booking);
    final profile = await CapabilityProfile.load();
    final g = Generator(PaperSize.mm58, profile);
    List<int> b = [];

    // Logo koperasi (hitam-putih) di atas karcis.
    final logo = await _loadLogo();
    if (logo != null) {
      b += g.image(logo);
      b += g.feed(1);
    }

    b += g.text('KOPERASI ANGKASA JAYA',
        styles: const PosStyles(
            align: PosAlign.center,
            bold: true,
            height: PosTextSize.size2,
            width: PosTextSize.size2));
    b += g.text('Bandar Udara APT. Pranoto Samarinda',
        styles: const PosStyles(align: PosAlign.center));
    b += g.text('KARCIS RESMI TAKSI',
        styles: const PosStyles(align: PosAlign.center, bold: true));
    b += g.hr();

    b += _kv(g, 'No.', f.code, bold: true);
    b += _kv(g, 'Waktu', f.dateTimeStr);
    b += _kv(g, 'Kasir', f.kasir);
    b += _kv(g, 'Supir', f.driverDisplay);
    b += g.hr(ch: '.');
    b += _kv(g, 'Tujuan', f.zoneName, bold: true);
    b += _kv(g, 'Bayar', f.methodLabel);
    b += g.hr();

    b += g.row([
      PosColumn(
          text: 'TOTAL',
          width: 5,
          styles: const PosStyles(bold: true, height: PosTextSize.size2)),
      PosColumn(
          text: f.amountStr,
          width: 7,
          styles: const PosStyles(
              align: PosAlign.right, bold: true, height: PosTextSize.size2)),
    ]);
    b += g.hr();
    b += g.feed(1);

    b += g.qrcode(f.publicUrl, size: QRSize.size6);
    b += g.text('Scan untuk cek struk online',
        styles: const PosStyles(align: PosAlign.center));
    b += g.feed(1);
    b += g.text('Terima kasih & selamat jalan',
        styles: const PosStyles(align: PosAlign.center));
    b += g.feed(2);
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

  static List<int> _kv(Generator g, String k, String v, {bool bold = false}) {
    return g.row([
      PosColumn(text: k, width: 4),
      PosColumn(
          text: v,
          width: 8,
          styles: PosStyles(align: PosAlign.right, bold: bold)),
    ]);
  }
}

/// Field karcis yang diolah dari data booking/transaksi.
class _TicketData {
  final String code;
  final String zoneName;
  final String driverDisplay;
  final String kasir;
  final String methodLabel;
  final String dateTimeStr;
  final String amountStr;
  final String publicUrl;

  _TicketData({
    required this.code,
    required this.zoneName,
    required this.driverDisplay,
    required this.kasir,
    required this.methodLabel,
    required this.dateTimeStr,
    required this.amountStr,
    required this.publicUrl,
  });

  factory _TicketData.from(Map<String, dynamic> booking) {
    Map<String, dynamic> m(dynamic v) =>
        (v as Map?)?.cast<String, dynamic>() ?? const {};
    final driver = m(booking['driver']);
    final profile = m(driver['driver_profile']);
    final zone = m(booking['zone_to']);
    final cso = m(booking['cso']);
    final tx = m(booking['transaction']);

    String s(dynamic v, [String fb = '-']) =>
        (v == null || v.toString().isEmpty) ? fb : v.toString();

    final line =
        profile['line_number'] == null ? '' : ' #L${profile['line_number']}';
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

    return _TicketData(
      code: code,
      zoneName: s(zone['name']),
      driverDisplay: '${s(driver['name'])}$line',
      kasir: s(cso['name']).split(' ').first,
      methodLabel: methodLabel,
      dateTimeStr: dateTimeStr,
      amountStr: formatRupiah(amount),
      publicUrl: '${ApiService.assetBaseUrl}/receipt/${s(tx['receipt_token'], code)}',
    );
  }
}
