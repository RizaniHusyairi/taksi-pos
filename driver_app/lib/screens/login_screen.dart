import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:google_fonts/google_fonts.dart';
import '../providers/auth_provider.dart';
import '../theme/app_colors.dart';
import '../widgets/sky_backdrop.dart';
import '../widgets/gradient_button.dart';
import '../widgets/fade_in.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _obscure = true;

  @override
  void dispose() {
    _usernameController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      resizeToAvoidBottomInset: true,
      body: SkyBackdrop(
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const SizedBox(height: 12),
                  // === Brand / Logo ===
                  FadeInUp(
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 26,
                        vertical: 20,
                      ),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(26),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withValues(alpha: 0.18),
                            blurRadius: 28,
                            offset: const Offset(0, 14),
                          ),
                        ],
                      ),
                      child: Image.asset(
                        'assets/images/logo_taksi.png',
                        height: 70,
                        fit: BoxFit.contain,
                      ),
                    ),
                  ),
                  const SizedBox(height: 22),
                  FadeInUp(
                    delayMs: 120,
                    child: Text(
                      'Selamat Datang, Driver',
                      textAlign: TextAlign.center,
                      style: GoogleFonts.outfit(
                        fontSize: 22,
                        fontWeight: FontWeight.w700,
                        color: Colors.white,
                        letterSpacing: 0.5,
                      ),
                    ),
                  ),
                  const SizedBox(height: 6),
                  FadeInUp(
                    delayMs: 200,
                    child: Text(
                      'Masuk untuk mulai mengangkasa',
                      textAlign: TextAlign.center,
                      style: GoogleFonts.outfit(
                        fontSize: 14,
                        color: Colors.white.withValues(alpha: 0.85),
                      ),
                    ),
                  ),
                  const SizedBox(height: 34),

                  // === Login Card ===
                  FadeInUp(
                    delayMs: 300,
                    child: Container(
                      padding: const EdgeInsets.all(22),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(26),
                        boxShadow: [
                          BoxShadow(
                            color: AppColors.deepBlue.withValues(alpha: 0.25),
                            blurRadius: 30,
                            offset: const Offset(0, 16),
                          ),
                        ],
                      ),
                      child: Form(
                        key: _formKey,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            _label('Username'),
                            const SizedBox(height: 8),
                            TextFormField(
                              controller: _usernameController,
                              style: GoogleFonts.outfit(color: AppColors.ink),
                              decoration: const InputDecoration(
                                hintText: 'Masukkan username',
                                prefixIcon: Icon(
                                  Icons.person_rounded,
                                  color: AppColors.cyan,
                                ),
                              ),
                              validator: (value) =>
                                  value!.isEmpty ? 'Wajib diisi' : null,
                            ),
                            const SizedBox(height: 18),
                            _label('Kata Sandi'),
                            const SizedBox(height: 8),
                            TextFormField(
                              controller: _passwordController,
                              obscureText: _obscure,
                              style: GoogleFonts.outfit(color: AppColors.ink),
                              decoration: InputDecoration(
                                hintText: 'Masukkan kata sandi',
                                prefixIcon: const Icon(
                                  Icons.lock_rounded,
                                  color: AppColors.cyan,
                                ),
                                suffixIcon: IconButton(
                                  icon: Icon(
                                    _obscure
                                        ? Icons.visibility_off_rounded
                                        : Icons.visibility_rounded,
                                    color: AppColors.inkFaint,
                                  ),
                                  onPressed: () =>
                                      setState(() => _obscure = !_obscure),
                                ),
                              ),
                              validator: (value) =>
                                  value!.isEmpty ? 'Wajib diisi' : null,
                            ),
                            const SizedBox(height: 28),
                            Consumer<AuthProvider>(
                              builder: (context, auth, _) {
                                return GradientButton(
                                  label: 'MASUK',
                                  icon: Icons.flight_takeoff_rounded,
                                  loading: auth.isLoading,
                                  onPressed: () => _handleLogin(auth),
                                );
                              },
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 28),
                  FadeInUp(
                    delayMs: 400,
                    child: Text(
                      'Taxi Angkasa Jaya • Mitra Resmi Bandara',
                      style: GoogleFonts.outfit(
                        color: Colors.white.withValues(alpha: 0.8),
                        fontSize: 12,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _handleLogin(AuthProvider auth) async {
    if (!_formKey.currentState!.validate()) return;
    var result = await auth.login(
      _usernameController.text,
      _passwordController.text,
    );
    if (result != true && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(result.toString()),
          backgroundColor: AppColors.danger,
          duration: const Duration(seconds: 5),
        ),
      );
    }
  }

  Widget _label(String text) => Text(
        text,
        style: GoogleFonts.outfit(
          color: AppColors.inkSoft,
          fontWeight: FontWeight.w600,
          fontSize: 13,
        ),
      );
}
