import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../providers/cso_order_provider.dart';
import '../../widgets/profile_avatar.dart';
import '../../theme/app_colors.dart';
import '../../widgets/app_bottom_nav.dart';
import 'cso_dashboard_screen.dart';
import 'cso_order_screen.dart';
import 'cso_history_screen.dart';
import 'cso_deposit_screen.dart';
import 'cso_profile_screen.dart';

/// Shell utama untuk peran CSO: Dashboard, Pemesanan Baru, Riwayat,
/// Setoran (serah terima tunai ke admin), dan Profil.
class CsoMainScreen extends StatefulWidget {
  const CsoMainScreen({super.key});

  @override
  State<CsoMainScreen> createState() => _CsoMainScreenState();
}

class _CsoMainScreenState extends State<CsoMainScreen> {
  int _currentIndex = 0;

  // Urutan sengaja menaruh "Pemesanan Baru" di tengah (indeks 2): itu aksi
  // utama CSO, dan di nav ia diangkat jadi tombol bulat yang menonjol.
  static const _titles = [
    'Dashboard',
    'Riwayat Transaksi',
    'Pemesanan Baru',
    'Setoran Tunai',
    'Profil Saya',
  ];

  static const _orderTabIndex = 2;

  @override
  Widget build(BuildContext context) {
    final screens = [
      CsoDashboardScreen(
        onBukaTab: (i) => setState(() => _currentIndex = i),
      ),
      const CsoHistoryScreen(),
      const CsoOrderScreen(),
      const CsoDepositScreen(),
      const CsoProfileScreen(),
    ];

    return ChangeNotifierProvider(
      create: (_) => CsoOrderProvider(),
      child: Scaffold(
        backgroundColor: AppColors.background,
        body: SafeArea(
          bottom: false,
          child: Column(
            children: [
              _currentIndex == 0
                  ? const _HeaderSapaan()
                  : _Header(title: _titles[_currentIndex]),
              Expanded(
                child: _TabTransition(
                  index: _currentIndex,
                  child: IndexedStack(index: _currentIndex, children: screens),
                ),
              ),
            ],
          ),
        ),
        bottomNavigationBar: AppBottomNav(
          currentIndex: _currentIndex,
          onTap: (index) => setState(() => _currentIndex = index),
          centerIndex: _orderTabIndex,
          items: const [
            NavItemData(Icons.dashboard_rounded, 'Home'),
            NavItemData(Icons.history_rounded, 'Riwayat'),
            NavItemData(Icons.add_rounded, 'Pesan'),
            NavItemData(Icons.account_balance_wallet_rounded, 'Setoran'),
            NavItemData(Icons.person_rounded, 'Profil'),
          ],
        ),
      ),
    );
  }
}

/// Fade + slide singkat setiap kali tab berganti. Sengaja membungkus
/// IndexedStack (bukan AnimatedSwitcher) supaya state tiap layar — posisi
/// scroll, isi form pemesanan — tetap hidup saat berpindah tab.
class _TabTransition extends StatefulWidget {
  final int index;
  final Widget child;
  const _TabTransition({required this.index, required this.child});

  @override
  State<_TabTransition> createState() => _TabTransitionState();
}

class _TabTransitionState extends State<_TabTransition>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 220),
    value: 1,
  );

  late final Animation<double> _fade = CurvedAnimation(
    parent: _c,
    curve: Curves.easeOut,
  );

  late final Animation<Offset> _slide =
      Tween(begin: const Offset(0, 0.02), end: Offset.zero).animate(
        CurvedAnimation(parent: _c, curve: Curves.easeOutCubic),
      );

  @override
  void didUpdateWidget(_TabTransition old) {
    super.didUpdateWidget(old);
    if (old.index != widget.index) _c.forward(from: 0);
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _fade,
      child: SlideTransition(position: _slide, child: widget.child),
    );
  }
}

/// Header tab Home: sapaan, tanggal, dan foto profil CSO.
class _HeaderSapaan extends StatelessWidget {
  const _HeaderSapaan();

  static const _hari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
  static const _bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
    'Agustus', 'September', 'Oktober', 'November', 'Desember'];

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user ?? const {};
    final nama = '${user['name'] ?? 'CSO'}'.trim();
    final depan = nama.split(' ').first;
    final now = DateTime.now();
    final salam = now.hour < 11
        ? 'Selamat pagi'
        : now.hour < 15
            ? 'Selamat siang'
            : now.hour < 18
                ? 'Selamat sore'
                : 'Selamat malam';

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 18),
      decoration: const BoxDecoration(
        gradient: AppColors.skyGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(24)),
      ),
      child: Row(
        children: [
          Expanded(
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: 0, end: 1),
              duration: const Duration(milliseconds: 500),
              curve: Curves.easeOutCubic,
              builder: (_, t, child) => Opacity(
                opacity: t,
                child: Transform.translate(offset: Offset(-12 * (1 - t), 0), child: child),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '$salam,',
                    style: const TextStyle(color: Colors.white70, fontSize: 12.5, fontWeight: FontWeight.w600),
                  ),
                  Text(
                    '$depan 👋',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: Colors.white, fontSize: 21, fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    '${_hari[now.weekday - 1]}, ${now.day} ${_bulan[now.month - 1]} ${now.year}',
                    style: const TextStyle(color: Colors.white70, fontSize: 12),
                  ),
                ],
              ),
            ),
          ),
          ProfileAvatar(
            photoPath: user['photo_path']?.toString(),
            inisial: nama.isEmpty ? 'C' : nama[0].toUpperCase(),
            size: 50,
            editable: false,
          ),
        ],
      ),
    );
  }
}

class _Header extends StatelessWidget {
  final String title;
  const _Header({required this.title});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 20),
      decoration: const BoxDecoration(
        gradient: AppColors.skyGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(24)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'CSO Panel',
            style: TextStyle(
              color: Colors.white70,
              fontSize: 12,
              fontWeight: FontWeight.w600,
              letterSpacing: 0.5,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            title,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 20,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}
