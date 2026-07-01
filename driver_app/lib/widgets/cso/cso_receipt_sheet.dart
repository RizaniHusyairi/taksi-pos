import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';
import '../../services/receipt_service.dart';
import '../../services/thermal_printer_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/format.dart';
import 'cso_printer_picker_sheet.dart';

/// Ringkasan struk setelah order berhasil dibuat, dengan QR ke struk publik.
/// Tombol "Cetak/Simpan" & "Bagikan" menghasilkan PDF struk (share ke WhatsApp dll).
class CsoReceiptSheet extends StatelessWidget {
  final Map<String, dynamic> booking;
  const CsoReceiptSheet({super.key, required this.booking});

  static Future<void> show(BuildContext context, Map<String, dynamic> booking) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => CsoReceiptSheet(booking: booking),
    );
  }

  String _str(dynamic v, [String fallback = '-']) =>
      (v == null || v.toString().isEmpty) ? fallback : v.toString();

  @override
  Widget build(BuildContext context) {
    final driver =
        (booking['driver'] as Map?)?.cast<String, dynamic>() ?? const {};
    final profile =
        (driver['driver_profile'] as Map?)?.cast<String, dynamic>() ?? const {};
    final zone =
        (booking['zone_to'] as Map?)?.cast<String, dynamic>() ?? const {};
    final cso = (booking['cso'] as Map?)?.cast<String, dynamic>() ?? const {};
    final tx =
        (booking['transaction'] as Map?)?.cast<String, dynamic>() ?? const {};

    final line = profile['line_number'] == null
        ? ''
        : ' #L${profile['line_number']}';
    final method = _str(tx['method'], 'QRIS');
    final methodLabel = method == 'CashDriver'
        ? 'Tunai (Supir)'
        : (method == 'CashCSO' ? 'Tunai (Kasir)' : 'QRIS');
    final price = booking['price'] is num
        ? booking['price'] as num
        : num.tryParse(booking['price']?.toString() ?? '0') ?? 0;
    final code = _str(tx['id'] ?? booking['id'], '-');
    final publicUrl = ReceiptService.publicUrlFor(booking);

    return DraggableScrollableSheet(
      initialChildSize: 0.74,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      expand: false,
      builder: (context, controller) => Container(
        decoration: const BoxDecoration(
          color: AppColors.background,
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        ),
        child: ListView(
          controller: controller,
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
          children: [
            Center(
              child: Container(
                width: 44,
                height: 5,
                decoration: BoxDecoration(
                  color: AppColors.inkFaint.withValues(alpha: 0.4),
                  borderRadius: BorderRadius.circular(3),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Center(
              child: Column(
                children: [
                  Container(
                    width: 64,
                    height: 64,
                    decoration: const BoxDecoration(
                      color: Color(0x1A18B27E),
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(
                      Icons.check_circle_rounded,
                      color: AppColors.success,
                      size: 40,
                    ),
                  ),
                  const SizedBox(height: 12),
                  const Text(
                    'Order Berhasil Dibuat',
                    style: TextStyle(
                      color: AppColors.ink,
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'No. $code',
                    style: const TextStyle(
                      color: AppColors.inkSoft,
                      fontSize: 13,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(18),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0x140B4DA2),
                    blurRadius: 16,
                    offset: Offset(0, 6),
                  ),
                ],
              ),
              child: Column(
                children: [
                  _row('Tujuan', 'Bandara → ${_str(zone['name'])}'),
                  _row('Supir', '${_str(driver['name'])}$line'),
                  _row('Penumpang', _str(booking['passenger_phone'])),
                  _row('Kasir', _str(cso['name'])),
                  _row('Metode', methodLabel),
                  const Divider(height: 22),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text(
                        'Total',
                        style: TextStyle(
                          color: AppColors.ink,
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      Text(
                        formatRupiah(price),
                        style: const TextStyle(
                          color: AppColors.deepBlue,
                          fontSize: 18,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            Center(
              child: Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Column(
                  children: [
                    QrImageView(
                      data: publicUrl,
                      size: 120,
                      backgroundColor: Colors.white,
                    ),
                    const SizedBox(height: 6),
                    const Text(
                      'Scan untuk cek struk online',
                      style: TextStyle(color: AppColors.inkSoft, fontSize: 11),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 18),
            // Cetak karcis ke printer thermal Bluetooth (aksi utama).
            SizedBox(
              height: 52,
              child: ElevatedButton.icon(
                onPressed: () => _printThermal(context),
                icon: const Icon(Icons.receipt_long_rounded, size: 20),
                label: const Text(
                  'Cetak Karcis',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5),
                ),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.deepBlue,
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14),
                  ),
                ),
              ),
            ),
            Center(
              child: TextButton.icon(
                onPressed: () => CsoPrinterPickerSheet.show(context),
                icon: const Icon(Icons.bluetooth_rounded, size: 15),
                label: const Text('Pilih / ganti printer'),
                style: TextButton.styleFrom(foregroundColor: AppColors.inkSoft),
              ),
            ),
            const SizedBox(height: 4),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => _share(context),
                    icon: const Icon(Icons.share_rounded, size: 18),
                    label: const Text('Bagikan'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: AppColors.deepBlue,
                      side: const BorderSide(color: AppColors.skyBlue),
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14),
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => _print(context),
                    icon: const Icon(Icons.picture_as_pdf_rounded, size: 18),
                    label: const Text('Simpan PDF'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: AppColors.inkSoft,
                      side: BorderSide(
                          color: AppColors.inkFaint.withValues(alpha: 0.5)),
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14),
                      ),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            SizedBox(
              height: 50,
              child: ElevatedButton(
                onPressed: () => Navigator.of(context).pop(),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.success,
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14),
                  ),
                ),
                child: const Text(
                  'Selesai',
                  style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 92,
            child: Text(
              label,
              style: const TextStyle(color: AppColors.inkSoft, fontSize: 13),
            ),
          ),
          Expanded(
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: const TextStyle(
                color: AppColors.ink,
                fontSize: 13.5,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _share(BuildContext context) async {
    try {
      await ReceiptService.sharePdf(booking);
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Gagal membuat PDF struk.')),
        );
      }
    }
  }

  Future<void> _print(BuildContext context) async {
    try {
      await ReceiptService.printPdf(booking);
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Gagal menyiapkan struk.')),
        );
      }
    }
  }

  /// Cetak karcis ke printer thermal Bluetooth. Kalau belum ada printer terpilih,
  /// buka pemilih dulu. Semua kegagalan tampil sebagai pesan ramah.
  Future<void> _printThermal(BuildContext context) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final mac = await ThermalPrinterService.savedMac();
      if (mac == null || mac.isEmpty) {
        if (!context.mounted) return;
        final picked = await CsoPrinterPickerSheet.show(context);
        if (picked != true) return;
      }
      messenger.showSnackBar(
        const SnackBar(content: Text('Mencetak karcis…')),
      );
      await ThermalPrinterService.printReceipt(booking);
      messenger.showSnackBar(
        const SnackBar(
          content: Text('Karcis tercetak ✓'),
          backgroundColor: AppColors.success,
        ),
      );
    } catch (e) {
      messenger.showSnackBar(
        SnackBar(
          content: Text(e.toString()),
          backgroundColor: AppColors.danger,
        ),
      );
    }
  }
}
