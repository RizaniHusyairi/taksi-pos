import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:google_fonts/google_fonts.dart';
import '../providers/auth_provider.dart';
import '../theme/app_colors.dart';
import '../widgets/sky_header.dart';
import '../widgets/fade_in.dart';
import '../widgets/profile_avatar.dart';
import 'edit_profile_screen.dart';
import 'change_password_screen.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final auth = Provider.of<AuthProvider>(context);
    final user = auth.user;
    final profile = user?['driver_profile'];

    return Scaffold(
      backgroundColor: AppColors.background,
      body: Column(
        children: [
          SkyHeader(
            title: 'Profil',
            subtitle: 'Kelola akun & kendaraan Anda',
            actions: [
              HeaderIconButton(
                icon: Icons.edit_rounded,
                tooltip: 'Edit profil',
                onTap: () => Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (context) => const EditProfileScreen(),
                  ),
                ),
              ),
            ],
            child: _profileHero(user),
          ),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(16, 22, 16, 24),
              child: Column(
                children: [
                  FadeInUp(
                    child: _infoTile(
                      Icons.directions_car_rounded,
                      'Mobil',
                      profile?['car_model'] ?? '-',
                    ),
                  ),
                  FadeInUp(
                    delayMs: 70,
                    child: _infoTile(
                      Icons.confirmation_number_rounded,
                      'Plat Nomor',
                      profile?['plate_number'] ?? '-',
                    ),
                  ),
                  FadeInUp(
                    delayMs: 140,
                    child: _infoTile(
                      Icons.phone_rounded,
                      'Nomor HP',
                      user?['phone_number'] ?? '-',
                    ),
                  ),
                  const SizedBox(height: 18),
                  FadeInUp(
                    delayMs: 200,
                    child: _actionTile(
                      icon: Icons.lock_reset_rounded,
                      label: 'Ganti Password',
                      color: AppColors.skyBlue,
                      onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (context) => const ChangePasswordScreen(),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  FadeInUp(
                    delayMs: 260,
                    child: _actionTile(
                      icon: Icons.logout_rounded,
                      label: 'Keluar',
                      color: AppColors.danger,
                      onTap: () => _konfirmasiKeluar(context, auth),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Satu-satunya jalan keluar akun supir (tombol di beranda sudah dihapus),
  /// jadi diberi konfirmasi: salah ketuk berarti order tidak masuk.
  Future<void> _konfirmasiKeluar(
    BuildContext context,
    AuthProvider auth,
  ) async {
    final keluar = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
        icon: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: AppColors.danger.withValues(alpha: 0.1),
            shape: BoxShape.circle,
          ),
          child: const Icon(
            Icons.logout_rounded,
            color: AppColors.danger,
            size: 30,
          ),
        ),
        title: Text(
          'Keluar dari akun?',
          textAlign: TextAlign.center,
          style: GoogleFonts.outfit(
            fontWeight: FontWeight.w800,
            color: AppColors.ink,
          ),
        ),
        content: Text(
          'Anda perlu masuk lagi untuk bisa menerima order.',
          textAlign: TextAlign.center,
          style: GoogleFonts.outfit(color: AppColors.inkSoft, height: 1.4),
        ),
        actionsAlignment: MainAxisAlignment.spaceEvenly,
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(
              'Batal',
              style: GoogleFonts.outfit(
                color: AppColors.inkSoft,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(
              'Ya, Keluar',
              style: GoogleFonts.outfit(fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
    if (keluar == true) await auth.logout();
  }

  static String _inisial(dynamic nama) {
    final n = '${nama ?? ''}'.trim();
    return n.isEmpty ? 'D' : n[0].toUpperCase();
  }

  Widget _profileHero(Map<String, dynamic>? user) {
    return Row(
      children: [
        ProfileAvatar(
          photoPath: user?['photo_path']?.toString(),
          inisial: _inisial(user?['name']),
          size: 76,
        ),
        const SizedBox(width: 16),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                user?['name'] ?? 'Driver Name',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: GoogleFonts.outfit(
                  fontSize: 20,
                  fontWeight: FontWeight.w700,
                  color: Colors.white,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                user?['email'] ?? 'email@example.com',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: GoogleFonts.outfit(
                  color: Colors.white.withValues(alpha: 0.85),
                  fontSize: 13,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _infoTile(IconData icon, String title, String value) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: [
          BoxShadow(
            color: AppColors.deepBlue.withValues(alpha: 0.05),
            blurRadius: 14,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: AppColors.paleBlue,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: AppColors.skyBlue, size: 20),
          ),
          const SizedBox(width: 14),
          Text(title, style: GoogleFonts.outfit(color: AppColors.inkSoft)),
          const Spacer(),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: GoogleFonts.outfit(
                color: AppColors.ink,
                fontWeight: FontWeight.bold,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _actionTile({
    required IconData icon,
    required String label,
    required Color color,
    required VoidCallback onTap,
  }) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(18),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: color.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(icon, color: color, size: 20),
              ),
              const SizedBox(width: 14),
              Text(
                label,
                style: GoogleFonts.outfit(
                  color: color,
                  fontWeight: FontWeight.w700,
                  fontSize: 15,
                ),
              ),
              const Spacer(),
              Icon(
                Icons.chevron_right_rounded,
                color: color.withValues(alpha: 0.6),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
