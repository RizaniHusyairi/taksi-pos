import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';
import '../../providers/cso_order_provider.dart';
import '../../theme/app_colors.dart';
import '../../utils/format.dart';

/// Bottom sheet pembayaran (langkah 2). Mengumpulkan No. WA penumpang +
/// metode (QRIS / Tunai Kasir / Tunai Supir). Untuk QRIS wajib unggah bukti.
/// Mengembalikan `true` lewat Navigator.pop bila pembayaran terverifikasi.
class CsoPaymentSheet extends StatefulWidget {
  const CsoPaymentSheet({super.key});

  static Future<bool?> show(BuildContext context) {
    final provider = context.read<CsoOrderProvider>();
    return showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => ChangeNotifierProvider<CsoOrderProvider>.value(
        value: provider,
        child: const CsoPaymentSheet(),
      ),
    );
  }

  @override
  State<CsoPaymentSheet> createState() => _CsoPaymentSheetState();
}

class _CsoPaymentSheetState extends State<CsoPaymentSheet> {
  final _phoneController = TextEditingController();
  CsoPaymentMethod? _method;
  String? _proofPath;

  @override
  void dispose() {
    _phoneController.dispose();
    super.dispose();
  }

  String? _validPhone() {
    final digits = _phoneController.text.replaceAll(RegExp(r'\D'), '');
    if (digits.length < 10) return null;
    return digits;
  }

  Future<void> _pickProof() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_library_rounded),
              title: const Text('Galeri'),
              onTap: () => Navigator.pop(ctx, ImageSource.gallery),
            ),
            ListTile(
              leading: const Icon(Icons.photo_camera_rounded),
              title: const Text('Kamera'),
              onTap: () => Navigator.pop(ctx, ImageSource.camera),
            ),
          ],
        ),
      ),
    );
    if (source == null) return;
    try {
      final file = await ImagePicker().pickImage(
        source: source,
        imageQuality: 70,
        maxWidth: 1600,
      );
      if (file != null) setState(() => _proofPath = file.path);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Gagal mengambil gambar.')),
        );
      }
    }
  }

  void _verify(CsoPaymentMethod method) {
    final phone = _validPhone();
    if (phone == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('No. WhatsApp penumpang wajib diisi (min. 10 digit).'),
        ),
      );
      return;
    }
    if (method == CsoPaymentMethod.qris && _proofPath == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Wajib unggah foto bukti transfer QRIS.')),
      );
      return;
    }
    context.read<CsoOrderProvider>().verifyPayment(
      method: method,
      passengerPhone: phone,
      proofPath: _proofPath,
    );
    Navigator.of(context).pop(true);
  }

  Future<void> _confirmCash(CsoPaymentMethod method) async {
    if (_validPhone() == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('No. WhatsApp penumpang wajib diisi (min. 10 digit).'),
        ),
      );
      return;
    }
    final label = method == CsoPaymentMethod.cashCso
        ? 'TUNAI KE KASIR'
        : 'TUNAI KE SUPIR';
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Konfirmasi Pembayaran'),
        content: Text('Pastikan pembayaran $label sudah diterima. Lanjutkan?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Ya, Lanjut'),
          ),
        ],
      ),
    );
    if (ok == true) _verify(method);
  }

  @override
  Widget build(BuildContext context) {
    final provider = context.watch<CsoOrderProvider>();
    final zone = provider.selectedZone;

    return DraggableScrollableSheet(
      initialChildSize: 0.82,
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
            const Text(
              'Pembayaran',
              style: TextStyle(
                color: AppColors.ink,
                fontSize: 20,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 14),
            // Ringkasan rute & tarif
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.paleBlue,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Expanded(
                    child: Text(
                      'Bandara → ${zone?.name ?? '-'}',
                      style: const TextStyle(
                        color: AppColors.ink,
                        fontWeight: FontWeight.w700,
                        fontSize: 14,
                      ),
                    ),
                  ),
                  Text(
                    formatRupiah(zone?.price ?? 0),
                    style: const TextStyle(
                      color: AppColors.deepBlue,
                      fontWeight: FontWeight.w800,
                      fontSize: 16,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            const Text(
              'No. WhatsApp Penumpang',
              style: TextStyle(
                color: AppColors.inkSoft,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 6),
            TextField(
              controller: _phoneController,
              keyboardType: TextInputType.phone,
              inputFormatters: [
                FilteringTextInputFormatter.allow(RegExp(r'[0-9+ ]')),
              ],
              decoration: InputDecoration(
                hintText: 'Contoh: 0812xxxxxxx',
                prefixIcon: const Icon(
                  Icons.phone_rounded,
                  color: AppColors.skyBlue,
                ),
                filled: true,
                fillColor: AppColors.surface,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: BorderSide.none,
                ),
              ),
            ),
            const SizedBox(height: 18),
            const Text(
              'Metode Pembayaran',
              style: TextStyle(
                color: AppColors.inkSoft,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 8),
            _methodTile(
              CsoPaymentMethod.qris,
              Icons.qr_code_rounded,
              'QRIS',
              'Transfer + unggah bukti',
            ),
            _methodTile(
              CsoPaymentMethod.cashCso,
              Icons.point_of_sale_rounded,
              'Tunai ke Kasir',
              'Penumpang bayar tunai ke CSO',
            ),
            _methodTile(
              CsoPaymentMethod.cashDriver,
              Icons.directions_car_rounded,
              'Tunai ke Supir',
              'Penumpang bayar tunai ke supir',
            ),
            if (_method == CsoPaymentMethod.qris) ...[
              const SizedBox(height: 14),
              _qrisPanel(provider),
            ],
            const SizedBox(height: 18),
            _proceedButton(),
          ],
        ),
      ),
    );
  }

  /// Tombol lanjut tunggal yang menyesuaikan metode terpilih — selalu terlihat
  /// agar CSO tahu langkah berikutnya (memperbaiki kebingungan urutan input).
  Widget _proceedButton() {
    final m = _method;
    final String label;
    final bool enabled;
    if (m == null) {
      label = 'Pilih Metode Pembayaran';
      enabled = false;
    } else if (m == CsoPaymentMethod.qris) {
      if (_proofPath == null) {
        label = 'Unggah Bukti Dulu';
        enabled = false;
      } else {
        label = 'Konfirmasi Pembayaran';
        enabled = true;
      }
    } else {
      label = 'Lanjutkan';
      enabled = true;
    }

    return SizedBox(
      height: 52,
      child: ElevatedButton.icon(
        onPressed: enabled ? _onProceed : null,
        icon: const Icon(Icons.arrow_forward_rounded),
        label: Text(
          label,
          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
        ),
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.success,
          foregroundColor: Colors.white,
          disabledBackgroundColor: AppColors.inkFaint,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
      ),
    );
  }

  void _onProceed() {
    final m = _method;
    if (m == null) return;
    if (m == CsoPaymentMethod.qris) {
      _verify(m);
    } else {
      _confirmCash(m);
    }
  }

  Widget _methodTile(
    CsoPaymentMethod method,
    IconData icon,
    String title,
    String subtitle,
  ) {
    final selected = _method == method;
    return GestureDetector(
      onTap: () => setState(() => _method = method),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: selected ? AppColors.skyBlue : Colors.transparent,
            width: 2,
          ),
        ),
        child: Row(
          children: [
            Icon(
              icon,
              color: selected ? AppColors.deepBlue : AppColors.inkFaint,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: const TextStyle(
                      color: AppColors.ink,
                      fontWeight: FontWeight.w700,
                      fontSize: 14,
                    ),
                  ),
                  Text(
                    subtitle,
                    style: const TextStyle(
                      color: AppColors.inkSoft,
                      fontSize: 12,
                    ),
                  ),
                ],
              ),
            ),
            if (selected)
              const Icon(Icons.check_circle_rounded, color: AppColors.skyBlue),
          ],
        ),
      ),
    );
  }

  Widget _qrisPanel(CsoOrderProvider provider) {
    final url = provider.companyQrisUrl;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        children: [
          if (url != null)
            GestureDetector(
              onTap: () => _zoom(url),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: Image.network(
                  url,
                  height: 160,
                  fit: BoxFit.contain,
                  errorBuilder: (_, _, _) => _qrisMissing(),
                ),
              ),
            )
          else
            _qrisMissing(),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: _pickProof,
            icon: Icon(
              _proofPath == null ? Icons.upload_rounded : Icons.check_rounded,
            ),
            label: Text(
              _proofPath == null ? 'Unggah Bukti Transfer' : 'Bukti Terpilih',
            ),
            style: OutlinedButton.styleFrom(
              foregroundColor: _proofPath == null
                  ? AppColors.deepBlue
                  : AppColors.success,
              side: BorderSide(
                color: _proofPath == null
                    ? AppColors.skyBlue
                    : AppColors.success,
              ),
              minimumSize: const Size.fromHeight(46),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
              ),
            ),
          ),
          if (_proofPath != null) ...[
            const SizedBox(height: 10),
            ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: Image.file(
                File(_proofPath!),
                height: 120,
                fit: BoxFit.cover,
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _qrisMissing() {
    return Container(
      height: 120,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: AppColors.paleBlue,
        borderRadius: BorderRadius.circular(10),
      ),
      child: const Padding(
        padding: EdgeInsets.all(12),
        child: Text(
          '⚠️ QRIS perusahaan belum diatur oleh Admin.',
          textAlign: TextAlign.center,
          style: TextStyle(
            color: AppColors.inkSoft,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    );
  }

  void _zoom(String url) {
    showDialog(
      context: context,
      builder: (ctx) => GestureDetector(
        onTap: () => Navigator.pop(ctx),
        child: Container(
          color: Colors.black87,
          alignment: Alignment.center,
          child: InteractiveViewer(child: Image.network(url)),
        ),
      ),
    );
  }
}
