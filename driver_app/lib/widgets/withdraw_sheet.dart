import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';

import '../theme/app_colors.dart';
import '../utils/api_error.dart';
import 'gradient_button.dart';

/// Dompet elektronik tujuan pencairan. Kuncinya harus sama persis dengan
/// DriverProfile::EWALLET_PROVIDERS di backend — validasi ada di sana.
const Map<String, String> kEwalletProviders = {
  'shopeepay': 'ShopeePay',
  'dana': 'DANA',
  'gopay': 'GoPay',
  'ovo': 'OVO',
};

/// Warna khas tiap e-wallet, supaya chip-nya langsung dikenali.
const Map<String, Color> _warnaEwallet = {
  'shopeepay': Color(0xFFEE4D2D),
  'dana': Color(0xFF118EEA),
  'gopay': Color(0xFF00AA13),
  'ovo': Color(0xFF4C3494),
};

/// Tujuan pencairan supir: rekening Bank BTN ATAU satu akun e-wallet.
class PayoutTujuan {
  final String method; // 'bank' | 'ewallet'
  final String accountNumber;
  final String provider;
  final String number;
  final String holder;

  const PayoutTujuan({
    required this.method,
    this.accountNumber = '',
    this.provider = '',
    this.number = '',
    this.holder = '',
  });

  bool get isEwallet => method == 'ewallet';

  /// "BTN •••• 3344" / "DANA •••• 7890" — untuk layar sukses.
  String get labelSamaran {
    final nomor = isEwallet ? number : accountNumber;
    final ujung = nomor.length <= 4
        ? nomor
        : '•••• ${nomor.substring(nomor.length - 4)}';
    return isEwallet
        ? '${kEwalletProviders[provider] ?? 'E-Wallet'} $ujung'
        : 'Bank BTN $ujung';
  }
}

/// Lembar Tarik Dana: pilih Bank BTN / E-Wallet, periksa atau isi nomornya
/// langsung di sini, lalu ajukan — satu alur, tanpa harus mengatur tujuan
/// di layar lain lebih dulu.
///
/// [onSubmit] menerima tujuan yang sudah (mungkin) diedit dan melempar bila
/// gagal; lembar menampilkan pesannya tanpa menutup diri. Server menyimpan
/// tujuan baru hanya bila penarikannya diterima.
class WithdrawSheet extends StatefulWidget {
  final double amount;
  final double debt;
  final PayoutTujuan saved;
  final Future<void> Function(PayoutTujuan tujuan) onSubmit;

  const WithdrawSheet({
    super.key,
    required this.amount,
    required this.debt,
    required this.saved,
    required this.onSubmit,
  });

  /// Mengembalikan true bila penarikan berhasil diajukan.
  static Future<bool> tampilkan(
    BuildContext context, {
    required double amount,
    required double debt,
    required PayoutTujuan saved,
    required Future<void> Function(PayoutTujuan tujuan) onSubmit,
  }) async {
    final hasil = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => WithdrawSheet(
        amount: amount,
        debt: debt,
        saved: saved,
        onSubmit: onSubmit,
      ),
    );
    return hasil == true;
  }

  @override
  State<WithdrawSheet> createState() => _WithdrawSheetState();
}

class _WithdrawSheetState extends State<WithdrawSheet> {
  final _rupiah = NumberFormat.currency(
    locale: 'id_ID',
    symbol: 'Rp ',
    decimalDigits: 0,
  );

  late String _metode = widget.saved.isEwallet ? 'ewallet' : 'bank';
  late String _provider = widget.saved.provider.isNotEmpty
      ? widget.saved.provider
      : 'dana';
  late final _rekening = TextEditingController(
    text: widget.saved.accountNumber,
  );
  late final _nomor = TextEditingController(text: widget.saved.number);
  late final _pemilik = TextEditingController(text: widget.saved.holder);

  bool _mengirim = false;
  String? _galat;
  PayoutTujuan? _sukses;

  @override
  void initState() {
    super.initState();
    for (final c in [_rekening, _nomor, _pemilik]) {
      c.addListener(() => setState(() => _galat = null));
    }
  }

  @override
  void dispose() {
    _rekening.dispose();
    _nomor.dispose();
    _pemilik.dispose();
    super.dispose();
  }

  bool get _ewallet => _metode == 'ewallet';

  PayoutTujuan get _isian => PayoutTujuan(
    method: _metode,
    accountNumber: _rekening.text.trim(),
    provider: _provider,
    number: _nomor.text.trim(),
    holder: _pemilik.text.trim(),
  );

  // Nomor e-wallet mengikuti DriverApiController::validasiTujuanPencairan
  // (10–15 digit). Rekening sengaja sedikit lebih ketat dari server (min. 6)
  // untuk menangkap salah ketik sebelum uang dikirim.
  String? get _galatRekening {
    final v = _rekening.text.trim();
    if (v.isEmpty) return null;
    return v.length < 6 ? 'Nomor rekening terlalu pendek' : null;
  }

  String? get _galatNomor {
    final v = _nomor.text.trim();
    if (v.isEmpty) return null;
    return (v.length < 10 || v.length > 15)
        ? 'Nomor e-wallet harus 10–15 digit'
        : null;
  }

  bool get _valid => _ewallet
      ? _nomor.text.trim().isNotEmpty &&
            _galatNomor == null &&
            _pemilik.text.trim().isNotEmpty
      : _rekening.text.trim().isNotEmpty && _galatRekening == null;

  /// Isian berbeda dari yang tersimpan → server akan menyimpannya.
  bool get _berubah {
    final s = widget.saved;
    final i = _isian;
    if (i.method != s.method) return true;
    return i.isEwallet
        ? i.provider != s.provider ||
              i.number != s.number ||
              i.holder != s.holder
        : i.accountNumber != s.accountNumber;
  }

  Future<void> _kirim() async {
    FocusScope.of(context).unfocus();
    setState(() {
      _mengirim = true;
      _galat = null;
    });
    final tujuan = _isian;
    try {
      await widget.onSubmit(tujuan);
      if (mounted) setState(() => _sukses = tujuan);
    } catch (e) {
      if (mounted) setState(() => _galat = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _mengirim = false);
    }
  }

  // === Build ===============================================================

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        ),
        child: SafeArea(
          top: false,
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 10, 20, 20),
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 300),
              child: _sukses != null ? _layarSukses(_sukses!) : _formulir(),
            ),
          ),
        ),
      ),
    );
  }

  Widget _pegangan() => Center(
    child: Container(
      width: 44,
      height: 5,
      margin: const EdgeInsets.only(bottom: 16),
      decoration: BoxDecoration(
        color: AppColors.inkFaint.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(10),
      ),
    ),
  );

  Widget _formulir() {
    return Column(
      key: const ValueKey('form'),
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _pegangan(),
        Text(
          'Tarik Dana',
          style: GoogleFonts.outfit(
            fontSize: 20,
            fontWeight: FontWeight.w800,
            color: AppColors.ink,
          ),
        ),
        const SizedBox(height: 14),
        _kartuNominal(),
        const SizedBox(height: 20),
        _judulBagian('1', 'Kirim ke'),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: _kartuMetode(
                'bank',
                Icons.account_balance_rounded,
                'Bank BTN',
                'Rekening tabungan',
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _kartuMetode(
                'ewallet',
                Icons.account_balance_wallet_rounded,
                'E-Wallet',
                'DANA, GoPay, OVO…',
              ),
            ),
          ],
        ),
        const SizedBox(height: 20),
        _judulBagian('2', _ewallet ? 'Akun e-wallet' : 'Nomor rekening'),
        const SizedBox(height: 10),
        AnimatedSwitcher(
          duration: const Duration(milliseconds: 250),
          transitionBuilder: (child, anim) => FadeTransition(
            opacity: anim,
            child: SizeTransition(sizeFactor: anim, child: child),
          ),
          child: _ewallet ? _isianEwallet() : _isianBank(),
        ),
        if (_berubah && _valid) ...[
          const SizedBox(height: 10),
          _catatan(
            Icons.bookmark_added_rounded,
            AppColors.skyBlue,
            'Tujuan ini akan disimpan untuk penarikan berikutnya.',
          ),
        ],
        if (_galat != null) ...[
          const SizedBox(height: 10),
          _catatan(Icons.error_outline_rounded, AppColors.danger, _galat!),
        ],
        const SizedBox(height: 20),
        GradientButton(
          label: 'TARIK ${_rupiah.format(widget.amount)}',
          icon: Icons.arrow_outward_rounded,
          loading: _mengirim,
          onPressed: _valid && !_mengirim ? _kirim : null,
        ),
        const SizedBox(height: 8),
        Text(
          'Dana dikirim manual oleh admin, biasanya di hari kerja yang sama.',
          textAlign: TextAlign.center,
          style: GoogleFonts.outfit(fontSize: 11.5, color: AppColors.inkFaint),
        ),
      ],
    );
  }

  Widget _kartuNominal() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: AppColors.skyGradient,
        borderRadius: BorderRadius.circular(18),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Jumlah yang dikirim',
                  style: GoogleFonts.outfit(
                    color: Colors.white.withValues(alpha: 0.85),
                    fontSize: 12.5,
                  ),
                ),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    _rupiah.format(widget.amount),
                    style: GoogleFonts.outfit(
                      color: Colors.white,
                      fontSize: 28,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
                if (widget.debt > 0)
                  Text(
                    'Sudah dipotong setoran ${_rupiah.format(widget.debt)}',
                    style: GoogleFonts.outfit(
                      color: Colors.white.withValues(alpha: 0.85),
                      fontSize: 11.5,
                    ),
                  ),
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.18),
              shape: BoxShape.circle,
            ),
            child: const Icon(Icons.payments_rounded, color: Colors.white),
          ),
        ],
      ),
    );
  }

  Widget _judulBagian(String no, String teks) {
    return Row(
      children: [
        Container(
          width: 22,
          height: 22,
          alignment: Alignment.center,
          decoration: const BoxDecoration(
            color: AppColors.paleBlue,
            shape: BoxShape.circle,
          ),
          child: Text(
            no,
            style: GoogleFonts.outfit(
              fontSize: 12,
              fontWeight: FontWeight.w800,
              color: AppColors.skyBlue,
            ),
          ),
        ),
        const SizedBox(width: 8),
        Text(
          teks,
          style: GoogleFonts.outfit(
            fontSize: 14.5,
            fontWeight: FontWeight.w700,
            color: AppColors.ink,
          ),
        ),
      ],
    );
  }

  Widget _kartuMetode(String metode, IconData ikon, String judul, String sub) {
    final aktif = _metode == metode;
    return GestureDetector(
      onTap: _mengirim
          ? null
          : () => setState(() {
              _metode = metode;
              _galat = null;
            }),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 220),
        curve: Curves.easeOut,
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: aktif ? AppColors.paleBlue : Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: aktif
                ? AppColors.skyBlue
                : AppColors.inkFaint.withValues(alpha: 0.35),
            width: aktif ? 2 : 1,
          ),
          boxShadow: aktif
              ? [
                  BoxShadow(
                    color: AppColors.skyBlue.withValues(alpha: 0.18),
                    blurRadius: 14,
                    offset: const Offset(0, 6),
                  ),
                ]
              : null,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                AnimatedContainer(
                  duration: const Duration(milliseconds: 220),
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: aktif ? AppColors.skyBlue : AppColors.paleBlue,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Icon(
                    ikon,
                    size: 20,
                    color: aktif ? Colors.white : AppColors.skyBlue,
                  ),
                ),
                const Spacer(),
                AnimatedScale(
                  scale: aktif ? 1 : 0,
                  duration: const Duration(milliseconds: 220),
                  curve: Curves.easeOutBack,
                  child: const Icon(
                    Icons.check_circle_rounded,
                    color: AppColors.skyBlue,
                    size: 20,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Text(
              judul,
              style: GoogleFonts.outfit(
                fontSize: 14,
                fontWeight: FontWeight.w800,
                color: AppColors.ink,
              ),
            ),
            Text(
              sub,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.outfit(fontSize: 11, color: AppColors.inkSoft),
            ),
          ],
        ),
      ),
    );
  }

  Widget _isianBank() {
    return Column(
      key: const ValueKey('bank'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _kolom(
          controller: _rekening,
          label: 'Nomor rekening BTN',
          hint: 'Masukkan nomor rekening',
          ikon: Icons.credit_card_rounded,
          angka: true,
          galat: _galatRekening,
        ),
      ],
    );
  }

  Widget _isianEwallet() {
    return Column(
      key: const ValueKey('ewallet'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: kEwalletProviders.entries.map((e) {
            final aktif = _provider == e.key;
            final warna = _warnaEwallet[e.key] ?? AppColors.skyBlue;
            return ChoiceChip(
              selected: aktif,
              showCheckmark: false,
              label: Text(e.value),
              labelStyle: GoogleFonts.outfit(
                fontWeight: FontWeight.w700,
                fontSize: 13,
                color: aktif ? Colors.white : warna,
              ),
              selectedColor: warna,
              backgroundColor: warna.withValues(alpha: 0.08),
              side: BorderSide(color: warna.withValues(alpha: aktif ? 1 : 0.4)),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(20),
              ),
              onSelected: _mengirim
                  ? null
                  : (_) => setState(() {
                      _provider = e.key;
                      _galat = null;
                    }),
            );
          }).toList(),
        ),
        const SizedBox(height: 12),
        _kolom(
          controller: _nomor,
          label: 'Nomor ${kEwalletProviders[_provider] ?? 'e-wallet'}',
          hint: 'Contoh: 081234567890',
          ikon: Icons.phone_android_rounded,
          angka: true,
          galat: _galatNomor,
        ),
        const SizedBox(height: 10),
        _kolom(
          controller: _pemilik,
          label: 'Nama pemilik akun',
          hint: 'Sesuai nama di aplikasi e-wallet',
          ikon: Icons.person_rounded,
        ),
      ],
    );
  }

  Widget _kolom({
    required TextEditingController controller,
    required String label,
    required String hint,
    required IconData ikon,
    bool angka = false,
    String? galat,
  }) {
    final kosong = controller.text.trim().isEmpty;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: GoogleFonts.outfit(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w600,
                  color: AppColors.inkSoft,
                ),
              ),
            ),
            if (kosong)
              Text(
                'Belum diisi — isi dulu',
                style: GoogleFonts.outfit(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: AppColors.warning,
                ),
              ),
          ],
        ),
        const SizedBox(height: 6),
        TextField(
          controller: controller,
          enabled: !_mengirim,
          keyboardType: angka ? TextInputType.number : TextInputType.name,
          textCapitalization: angka
              ? TextCapitalization.none
              : TextCapitalization.words,
          inputFormatters: angka
              ? [
                  FilteringTextInputFormatter.digitsOnly,
                  LengthLimitingTextInputFormatter(20),
                ]
              : [LengthLimitingTextInputFormatter(100)],
          style: GoogleFonts.outfit(
            fontSize: 16,
            fontWeight: FontWeight.w700,
            letterSpacing: angka ? 1 : 0,
            color: AppColors.ink,
          ),
          decoration: InputDecoration(
            hintText: hint,
            hintStyle: GoogleFonts.outfit(
              fontSize: 14,
              fontWeight: FontWeight.w400,
              letterSpacing: 0,
              color: AppColors.inkFaint,
            ),
            errorText: galat,
            prefixIcon: Icon(ikon, color: AppColors.skyBlue),
            suffixIcon: kosong
                ? null
                : const Icon(
                    Icons.edit_rounded,
                    size: 18,
                    color: AppColors.inkFaint,
                  ),
            filled: true,
            fillColor: kosong
                ? AppColors.warning.withValues(alpha: 0.06)
                : AppColors.background,
            contentPadding: const EdgeInsets.symmetric(
              vertical: 14,
              horizontal: 12,
            ),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide.none,
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide(
                color: kosong
                    ? AppColors.warning.withValues(alpha: 0.5)
                    : AppColors.inkFaint.withValues(alpha: 0.25),
              ),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: const BorderSide(color: AppColors.skyBlue, width: 2),
            ),
          ),
        ),
      ],
    );
  }

  Widget _catatan(IconData ikon, Color warna, String teks) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: warna.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(ikon, size: 16, color: warna),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              teks,
              style: GoogleFonts.outfit(
                fontSize: 12,
                color: warna,
                height: 1.3,
              ),
            ),
          ),
        ],
      ),
    );
  }

  // === Sukses ==============================================================

  Widget _layarSukses(PayoutTujuan tujuan) {
    return Column(
      key: const ValueKey('sukses'),
      mainAxisSize: MainAxisSize.min,
      children: [
        _pegangan(),
        const SizedBox(height: 8),
        TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: 1),
          duration: const Duration(milliseconds: 600),
          curve: Curves.elasticOut,
          builder: (_, v, child) => Transform.scale(scale: v, child: child),
          child: Container(
            width: 84,
            height: 84,
            decoration: BoxDecoration(
              color: AppColors.success,
              shape: BoxShape.circle,
              boxShadow: [
                BoxShadow(
                  color: AppColors.success.withValues(alpha: 0.35),
                  blurRadius: 24,
                  offset: const Offset(0, 10),
                ),
              ],
            ),
            child: const Icon(
              Icons.check_rounded,
              color: Colors.white,
              size: 48,
            ),
          ),
        ),
        const SizedBox(height: 18),
        Text(
          'Penarikan diajukan!',
          style: GoogleFonts.outfit(
            fontSize: 20,
            fontWeight: FontWeight.w800,
            color: AppColors.ink,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          _rupiah.format(widget.amount),
          style: GoogleFonts.outfit(
            fontSize: 30,
            fontWeight: FontWeight.w900,
            color: AppColors.success,
          ),
        ),
        const SizedBox(height: 14),
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: AppColors.background,
            borderRadius: BorderRadius.circular(16),
          ),
          child: Column(
            children: [
              _barisSukses(
                tujuan.isEwallet
                    ? Icons.account_balance_wallet_rounded
                    : Icons.account_balance_rounded,
                'Dikirim ke',
                tujuan.labelSamaran,
              ),
              const SizedBox(height: 10),
              _barisSukses(
                Icons.schedule_rounded,
                'Status',
                'Menunggu diproses admin',
              ),
            ],
          ),
        ),
        const SizedBox(height: 10),
        Text(
          'Bukti transfer akan muncul di Riwayat Penarikan setelah dana dikirim.',
          textAlign: TextAlign.center,
          style: GoogleFonts.outfit(fontSize: 12, color: AppColors.inkSoft),
        ),
        const SizedBox(height: 20),
        GradientButton(
          label: 'SELESAI',
          icon: Icons.check_circle_rounded,
          gradient: const LinearGradient(
            colors: [Color(0xFF12A06B), AppColors.success],
          ),
          onPressed: () => Navigator.pop(context, true),
        ),
      ],
    );
  }

  Widget _barisSukses(IconData ikon, String label, String nilai) {
    return Row(
      children: [
        Icon(ikon, size: 18, color: AppColors.skyBlue),
        const SizedBox(width: 10),
        Text(
          label,
          style: GoogleFonts.outfit(fontSize: 12.5, color: AppColors.inkSoft),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            nilai,
            textAlign: TextAlign.right,
            style: GoogleFonts.outfit(
              fontSize: 13,
              fontWeight: FontWeight.w700,
              color: AppColors.ink,
            ),
          ),
        ),
      ],
    );
  }
}
