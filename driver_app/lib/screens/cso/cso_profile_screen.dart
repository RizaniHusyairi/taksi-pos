import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/api_error.dart';

/// Tab Profil CSO — desain bold & interaktif: hero gradien dengan avatar
/// (ring + indikator online berdenyut), animasi masuk fade/slide, kartu
/// Edit Biodata & Ganti Password yang bisa dibuka-tutup, plus toggle password.
class CsoProfileScreen extends StatefulWidget {
  const CsoProfileScreen({super.key});

  @override
  State<CsoProfileScreen> createState() => _CsoProfileScreenState();
}

class _CsoProfileScreenState extends State<CsoProfileScreen> {
  final _api = ApiService();
  final _nameCtrl = TextEditingController();
  final _usernameCtrl = TextEditingController();
  final _currentPassCtrl = TextEditingController();
  final _newPassCtrl = TextEditingController();
  final _confirmPassCtrl = TextEditingController();

  bool _savingProfile = false;
  bool _savingPass = false;

  bool _editOpen = false;
  bool _passOpen = false;
  bool _obCurrent = true, _obNew = true, _obConfirm = true;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final user = context.read<AuthProvider>().user;
      _nameCtrl.text = (user?['name'] ?? '').toString();
      _usernameCtrl.text = (user?['username'] ?? '').toString();
    });
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _usernameCtrl.dispose();
    _currentPassCtrl.dispose();
    _newPassCtrl.dispose();
    _confirmPassCtrl.dispose();
    super.dispose();
  }

  void _toast(String msg) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
  }

  Future<void> _saveProfile() async {
    final name = _nameCtrl.text.trim();
    final username = _usernameCtrl.text.trim();
    if (name.isEmpty || username.isEmpty) {
      _toast('Nama dan username wajib diisi.');
      return;
    }
    setState(() => _savingProfile = true);
    try {
      await _api.updateCsoProfile({'name': name, 'username': username});
      if (mounted) await context.read<AuthProvider>().fetchProfile();
      _toast('Profil berhasil diperbarui.');
    } catch (e) {
      _toast(apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _savingProfile = false);
    }
  }

  Future<void> _changePassword() async {
    final current = _currentPassCtrl.text;
    final newPass = _newPassCtrl.text;
    final confirm = _confirmPassCtrl.text;

    if (current.isEmpty || newPass.isEmpty || confirm.isEmpty) {
      _toast('Semua kolom password wajib diisi.');
      return;
    }
    if (newPass.length < 6) {
      _toast('Password baru minimal 6 karakter.');
      return;
    }
    if (newPass != confirm) {
      _toast('Konfirmasi password baru tidak cocok.');
      return;
    }
    setState(() => _savingPass = true);
    try {
      await _api.changeCsoPassword(current, newPass);
      _currentPassCtrl.clear();
      _newPassCtrl.clear();
      _confirmPassCtrl.clear();
      _toast('Password berhasil diubah.');
      if (mounted) setState(() => _passOpen = false);
    } catch (e) {
      _toast(apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _savingPass = false);
    }
  }

  Future<void> _confirmLogout() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Keluar'),
        content: const Text('Yakin ingin keluar dari akun ini?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text(
              'Keluar',
              style: TextStyle(color: AppColors.danger),
            ),
          ),
        ],
      ),
    );
    if (ok == true && mounted) {
      await context.read<AuthProvider>().logout();
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final user = auth.user ?? const {};
    final name = (user['name'] ?? 'CSO').toString();
    final username = (user['username'] ?? '-').toString();
    final initial = name.isNotEmpty ? name[0].toUpperCase() : 'C';

    // Animasi masuk: fade + slide-up (sekali saat dibuka).
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: const Duration(milliseconds: 480),
      curve: Curves.easeOutCubic,
      builder: (context, t, child) => Opacity(
        opacity: t.clamp(0, 1),
        child: Transform.translate(
          offset: Offset(0, (1 - t) * 26),
          child: child,
        ),
      ),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        children: [
          _hero(name, username, initial),
          const SizedBox(height: 18),
          _expandable(
            icon: Icons.badge_rounded,
            color: AppColors.skyBlue,
            title: 'Edit Biodata',
            subtitle: 'Ubah nama & username',
            open: _editOpen,
            onToggle: () => setState(() => _editOpen = !_editOpen),
            child: Column(
              children: [
                _textField(_nameCtrl, 'Nama', Icons.person_rounded),
                const SizedBox(height: 12),
                _textField(
                  _usernameCtrl,
                  'Username',
                  Icons.alternate_email_rounded,
                ),
                const SizedBox(height: 14),
                _primaryButton(
                  'Simpan Perubahan',
                  Icons.check_rounded,
                  _savingProfile,
                  _saveProfile,
                  AppColors.deepBlue,
                ),
              ],
            ),
          ),
          _expandable(
            icon: Icons.lock_rounded,
            color: AppColors.gold,
            title: 'Ganti Password',
            subtitle: 'Perbarui kata sandi akun',
            open: _passOpen,
            onToggle: () => setState(() => _passOpen = !_passOpen),
            child: Column(
              children: [
                _passwordField(
                  _currentPassCtrl,
                  'Password Saat Ini',
                  _obCurrent,
                  () => setState(() => _obCurrent = !_obCurrent),
                ),
                const SizedBox(height: 12),
                _passwordField(
                  _newPassCtrl,
                  'Password Baru',
                  _obNew,
                  () => setState(() => _obNew = !_obNew),
                ),
                const SizedBox(height: 12),
                _passwordField(
                  _confirmPassCtrl,
                  'Konfirmasi Password Baru',
                  _obConfirm,
                  () => setState(() => _obConfirm = !_obConfirm),
                ),
                const SizedBox(height: 14),
                _primaryButton(
                  'Ubah Password',
                  Icons.lock_reset_rounded,
                  _savingPass,
                  _changePassword,
                  AppColors.gold,
                ),
              ],
            ),
          ),
          const SizedBox(height: 6),
          _logoutTile(),
          const SizedBox(height: 18),
          const Center(
            child: Text(
              'Taxi Angkasa Jaya · CSO',
              style: TextStyle(color: AppColors.inkFaint, fontSize: 11.5),
            ),
          ),
        ],
      ),
    );
  }

  Widget _hero(String name, String username, String initial) {
    return Container(
      padding: const EdgeInsets.fromLTRB(22, 24, 22, 24),
      decoration: BoxDecoration(
        gradient: AppColors.skyGradient,
        borderRadius: BorderRadius.circular(26),
        boxShadow: const [
          BoxShadow(
            color: Color(0x4D0B4DA2),
            blurRadius: 22,
            offset: Offset(0, 12),
          ),
        ],
      ),
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Positioned(right: -24, top: -38, child: _ghostCircle(120)),
          Positioned(left: -30, bottom: -46, child: _ghostCircle(90)),
          Column(
            children: [
              // Avatar dengan ring gradien + indikator online berdenyut
              GestureDetector(
                onTap: () => _toast('Ganti foto profil segera hadir.'),
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    Container(
                      width: 96,
                      height: 96,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: const LinearGradient(
                          colors: [Colors.white, Color(0xFFBFE3FF)],
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                        ),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withValues(alpha: 0.15),
                            blurRadius: 12,
                            offset: const Offset(0, 6),
                          ),
                        ],
                      ),
                    ),
                    Container(
                      width: 84,
                      height: 84,
                      alignment: Alignment.center,
                      decoration: const BoxDecoration(
                        color: Colors.white,
                        shape: BoxShape.circle,
                      ),
                      child: Text(
                        initial,
                        style: const TextStyle(
                          color: AppColors.deepBlue,
                          fontSize: 38,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                    // indikator online berdenyut (kanan-bawah)
                    const Positioned(right: 6, bottom: 6, child: _PulseDot()),
                    // badge kamera (kanan-atas)
                    Positioned(
                      right: 2,
                      top: 4,
                      child: Container(
                        padding: const EdgeInsets.all(5),
                        decoration: BoxDecoration(
                          color: AppColors.deepBlue,
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 2),
                        ),
                        child: const Icon(
                          Icons.photo_camera_rounded,
                          color: Colors.white,
                          size: 13,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 14),
              Text(
                name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 21,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                '@$username',
                style: const TextStyle(color: Colors.white70, fontSize: 13.5),
              ),
              const SizedBox(height: 12),
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  _heroChip(
                    const Icon(
                      Icons.support_agent_rounded,
                      color: Colors.white,
                      size: 14,
                    ),
                    'CSO',
                  ),
                  const SizedBox(width: 8),
                  _heroChip(
                    Container(
                      width: 7,
                      height: 7,
                      decoration: const BoxDecoration(
                        color: AppColors.success,
                        shape: BoxShape.circle,
                      ),
                    ),
                    'Aktif',
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _ghostCircle(double size) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(
      color: Colors.white.withValues(alpha: 0.08),
      shape: BoxShape.circle,
    ),
  );

  Widget _heroChip(Widget leading, String text) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.22),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          leading,
          const SizedBox(width: 6),
          Text(
            text,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 12,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }

  Widget _expandable({
    required IconData icon,
    required Color color,
    required String title,
    required String subtitle,
    required bool open,
    required VoidCallback onToggle,
    required Widget child,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(
          color: open ? color.withValues(alpha: 0.5) : Colors.transparent,
          width: 1.5,
        ),
        boxShadow: const [
          BoxShadow(
            color: Color(0x0F0B4DA2),
            blurRadius: 12,
            offset: Offset(0, 5),
          ),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          InkWell(
            onTap: onToggle,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        colors: [color, color.withValues(alpha: 0.7)],
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                      ),
                      borderRadius: BorderRadius.circular(13),
                      boxShadow: [
                        BoxShadow(
                          color: color.withValues(alpha: 0.35),
                          blurRadius: 8,
                          offset: const Offset(0, 4),
                        ),
                      ],
                    ),
                    child: Icon(icon, color: Colors.white, size: 22),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          title,
                          style: const TextStyle(
                            color: AppColors.ink,
                            fontSize: 15,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          subtitle,
                          style: const TextStyle(
                            color: AppColors.inkSoft,
                            fontSize: 12.5,
                          ),
                        ),
                      ],
                    ),
                  ),
                  AnimatedRotation(
                    turns: open ? 0.5 : 0,
                    duration: const Duration(milliseconds: 250),
                    child: Icon(
                      Icons.keyboard_arrow_down_rounded,
                      color: color,
                    ),
                  ),
                ],
              ),
            ),
          ),
          AnimatedCrossFade(
            firstChild: const SizedBox(width: double.infinity, height: 0),
            secondChild: Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 18),
              child: child,
            ),
            crossFadeState: open
                ? CrossFadeState.showSecond
                : CrossFadeState.showFirst,
            duration: const Duration(milliseconds: 260),
            sizeCurve: Curves.easeInOut,
          ),
        ],
      ),
    );
  }

  Widget _textField(TextEditingController c, String label, IconData icon) {
    return TextField(
      controller: c,
      decoration: InputDecoration(
        labelText: label,
        prefixIcon: Icon(icon, color: AppColors.skyBlue, size: 20),
        filled: true,
        fillColor: AppColors.background,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide.none,
        ),
      ),
    );
  }

  Widget _passwordField(
    TextEditingController c,
    String label,
    bool obscure,
    VoidCallback onToggle,
  ) {
    return TextField(
      controller: c,
      obscureText: obscure,
      decoration: InputDecoration(
        labelText: label,
        prefixIcon: const Icon(
          Icons.lock_outline_rounded,
          color: AppColors.gold,
          size: 20,
        ),
        suffixIcon: IconButton(
          onPressed: onToggle,
          icon: Icon(
            obscure ? Icons.visibility_rounded : Icons.visibility_off_rounded,
            color: AppColors.inkFaint,
            size: 20,
          ),
        ),
        filled: true,
        fillColor: AppColors.background,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide.none,
        ),
      ),
    );
  }

  Widget _primaryButton(
    String label,
    IconData icon,
    bool loading,
    VoidCallback onPressed,
    Color color,
  ) {
    return SizedBox(
      width: double.infinity,
      height: 48,
      child: ElevatedButton.icon(
        onPressed: loading ? null : onPressed,
        icon: loading
            ? const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(
                  strokeWidth: 2,
                  color: Colors.white,
                ),
              )
            : Icon(icon, size: 18),
        label: Text(
          loading ? 'Menyimpan...' : label,
          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
        ),
        style: ElevatedButton.styleFrom(
          backgroundColor: color,
          foregroundColor: Colors.white,
          disabledBackgroundColor: AppColors.inkFaint,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
    );
  }

  Widget _logoutTile() {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.danger.withValues(alpha: 0.06),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: AppColors.danger.withValues(alpha: 0.25)),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: _confirmLogout,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: AppColors.danger.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(13),
                ),
                child: const Icon(
                  Icons.logout_rounded,
                  color: AppColors.danger,
                  size: 22,
                ),
              ),
              const SizedBox(width: 14),
              const Expanded(
                child: Text(
                  'Keluar',
                  style: TextStyle(
                    color: AppColors.danger,
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              const Icon(Icons.chevron_right_rounded, color: AppColors.danger),
            ],
          ),
        ),
      ),
    );
  }
}

/// Titik "online" hijau dengan cincin yang berdenyut (animasi berulang).
class _PulseDot extends StatefulWidget {
  const _PulseDot();

  @override
  State<_PulseDot> createState() => _PulseDotState();
}

class _PulseDotState extends State<_PulseDot>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1500),
  )..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 22,
      height: 22,
      child: AnimatedBuilder(
        animation: _c,
        builder: (context, _) {
          final t = _c.value;
          return Stack(
            alignment: Alignment.center,
            children: [
              Container(
                width: 12 + t * 10,
                height: 12 + t * 10,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: AppColors.success.withValues(alpha: (1 - t) * 0.45),
                ),
              ),
              Container(
                width: 14,
                height: 14,
                decoration: BoxDecoration(
                  color: AppColors.success,
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white, width: 2.5),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
