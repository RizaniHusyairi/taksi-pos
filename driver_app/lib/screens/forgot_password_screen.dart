import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import '../utils/api_error.dart';
import '../widgets/gradient_button.dart';
import '../widgets/sky_header.dart';

/// Lupa password lewat OTP WhatsApp (PasswordResetController).
///
/// Langkah 1: username / nomor HP → kode dikirim ke WA yang terdaftar.
/// Langkah 2: kode + password baru. Setelah berhasil, semua perangkat
/// dikeluarkan server dan pengguna kembali ke layar login.
class ForgotPasswordScreen extends StatefulWidget {
  final String initialLogin;

  const ForgotPasswordScreen({super.key, this.initialLogin = ''});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  /// Sama dengan PasswordResetController::COOLDOWN_SECONDS.
  static const _jedaKirimUlang = 60;

  final _api = ApiService();
  final _formKey = GlobalKey<FormState>();
  late final _loginCtrl = TextEditingController(text: widget.initialLogin);
  final _kodeCtrl = TextEditingController();
  final _pwCtrl = TextEditingController();
  final _pw2Ctrl = TextEditingController();

  bool _kodeTerkirim = false;
  bool _loading = false;
  bool _obscure = true;
  int _sisaJeda = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _loginCtrl.dispose();
    _kodeCtrl.dispose();
    _pwCtrl.dispose();
    _pw2Ctrl.dispose();
    super.dispose();
  }

  void _pesan(String teks) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(teks)));
  }

  void _mulaiJeda() {
    _timer?.cancel();
    setState(() => _sisaJeda = _jedaKirimUlang);
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return t.cancel();
      setState(() => _sisaJeda--);
      if (_sisaJeda <= 0) t.cancel();
    });
  }

  Future<void> _mintaKode() async {
    if (_loginCtrl.text.trim().isEmpty) {
      _pesan('Isi username atau nomor HP terlebih dahulu.');
      return;
    }
    setState(() => _loading = true);
    try {
      final res = await _api.forgotPassword(_loginCtrl.text.trim());
      if (!mounted) return;
      setState(() => _kodeTerkirim = true);
      _mulaiJeda();
      _pesan(res.data['message']?.toString() ?? 'Kode telah dikirim.');
    } catch (e) {
      if (mounted) _pesan(apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _reset() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _loading = true);
    try {
      final res = await _api.resetPassword(
        login: _loginCtrl.text.trim(),
        code: _kodeCtrl.text.trim(),
        password: _pwCtrl.text,
      );
      if (!mounted) return;
      _pesan(res.data['message']?.toString() ?? 'Password berhasil diganti.');
      Navigator.pop(context);
    } catch (e) {
      if (mounted) _pesan(apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      body: Column(
        children: [
          SkyHeader(
            title: 'Lupa Kata Sandi',
            subtitle: 'Kode verifikasi dikirim lewat WhatsApp',
            onBack: () => Navigator.pop(context),
          ),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(16, 22, 16, 24),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _label('Username atau Nomor HP'),
                    const SizedBox(height: 8),
                    TextFormField(
                      controller: _loginCtrl,
                      enabled: !_kodeTerkirim,
                      style: GoogleFonts.outfit(color: AppColors.ink),
                      decoration: const InputDecoration(
                        hintText: 'mis. budi01 atau 0812…',
                        prefixIcon: Icon(
                          Icons.person_rounded,
                          color: AppColors.cyan,
                        ),
                      ),
                    ),
                    if (!_kodeTerkirim) ...[
                      const SizedBox(height: 12),
                      Text(
                        'Kode dikirim ke nomor WhatsApp yang terdaftar di akun '
                        'Anda. Nomor sudah tidak aktif? Hubungi admin.',
                        style: GoogleFonts.outfit(
                          color: AppColors.inkFaint,
                          fontSize: 12.5,
                        ),
                      ),
                      const SizedBox(height: 26),
                      GradientButton(
                        label: 'KIRIM KODE',
                        icon: Icons.send_rounded,
                        loading: _loading,
                        onPressed: _mintaKode,
                      ),
                    ] else
                      ..._langkahDua(),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  List<Widget> _langkahDua() => [
        const SizedBox(height: 18),
        _label('Kode Verifikasi (6 digit)'),
        const SizedBox(height: 8),
        TextFormField(
          controller: _kodeCtrl,
          keyboardType: TextInputType.number,
          maxLength: 6,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          style: GoogleFonts.outfit(
            color: AppColors.ink,
            fontSize: 20,
            letterSpacing: 6,
            fontWeight: FontWeight.w700,
          ),
          validator: (v) =>
              (v == null || v.length != 6) ? 'Masukkan 6 digit kode' : null,
          decoration: const InputDecoration(
            counterText: '',
            prefixIcon: Icon(Icons.sms_rounded, color: AppColors.cyan),
          ),
        ),
        Align(
          alignment: Alignment.centerRight,
          child: TextButton(
            onPressed: (_sisaJeda > 0 || _loading) ? null : _mintaKode,
            child: Text(
              _sisaJeda > 0
                  ? 'Kirim ulang dalam $_sisaJeda dtk'
                  : 'Kirim ulang kode',
              style: GoogleFonts.outfit(fontWeight: FontWeight.w600),
            ),
          ),
        ),
        const SizedBox(height: 6),
        _label('Kata Sandi Baru'),
        const SizedBox(height: 8),
        _pwField(
          _pwCtrl,
          (v) => (v == null || v.length < 6) ? 'Minimal 6 karakter' : null,
        ),
        const SizedBox(height: 16),
        _label('Ulangi Kata Sandi Baru'),
        const SizedBox(height: 8),
        _pwField(
          _pw2Ctrl,
          (v) => v != _pwCtrl.text ? 'Kata sandi tidak sama' : null,
        ),
        const SizedBox(height: 28),
        GradientButton(
          label: 'SIMPAN KATA SANDI',
          icon: Icons.shield_rounded,
          loading: _loading,
          onPressed: _reset,
        ),
        const SizedBox(height: 8),
        TextButton(
          onPressed: _loading
              ? null
              : () => setState(() {
                    _kodeTerkirim = false;
                    _kodeCtrl.clear();
                  }),
          child: Text(
            'Ganti username / nomor',
            style: GoogleFonts.outfit(color: AppColors.inkFaint),
          ),
        ),
      ];

  Widget _pwField(
    TextEditingController ctrl,
    String? Function(String?) validator,
  ) {
    return TextFormField(
      controller: ctrl,
      obscureText: _obscure,
      style: GoogleFonts.outfit(color: AppColors.ink),
      validator: validator,
      decoration: InputDecoration(
        prefixIcon: const Icon(Icons.lock_rounded, color: AppColors.cyan),
        suffixIcon: IconButton(
          icon: Icon(
            _obscure ? Icons.visibility_off_rounded : Icons.visibility_rounded,
            color: AppColors.inkFaint,
          ),
          onPressed: () => setState(() => _obscure = !_obscure),
        ),
      ),
    );
  }

  Widget _label(String teks) => Text(
        teks,
        style: GoogleFonts.outfit(
          color: AppColors.inkSoft,
          fontWeight: FontWeight.w600,
          fontSize: 13,
        ),
      );
}
