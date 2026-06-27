import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/cso_order_provider.dart';
import '../../theme/app_colors.dart';
import '../../widgets/app_bottom_nav.dart';
import 'cso_dashboard_screen.dart';
import 'cso_order_screen.dart';
import 'cso_history_screen.dart';
import 'cso_profile_screen.dart';

/// Shell utama untuk peran CSO: tiga tab — Pemesanan Baru, Riwayat, Profil.
class CsoMainScreen extends StatefulWidget {
  const CsoMainScreen({super.key});

  @override
  State<CsoMainScreen> createState() => _CsoMainScreenState();
}

class _CsoMainScreenState extends State<CsoMainScreen> {
  int _currentIndex = 0;

  static const _titles = [
    'Dashboard',
    'Pemesanan Baru',
    'Riwayat Transaksi',
    'Profil Saya',
  ];

  @override
  Widget build(BuildContext context) {
    final screens = [
      const CsoDashboardScreen(),
      const CsoOrderScreen(),
      const CsoHistoryScreen(),
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
              _Header(title: _titles[_currentIndex]),
              Expanded(
                child: IndexedStack(index: _currentIndex, children: screens),
              ),
            ],
          ),
        ),
        bottomNavigationBar: AppBottomNav(
          currentIndex: _currentIndex,
          onTap: (index) => setState(() => _currentIndex = index),
          items: const [
            NavItemData(Icons.dashboard_rounded, 'Dashboard'),
            NavItemData(Icons.point_of_sale_rounded, 'Pesan'),
            NavItemData(Icons.history_rounded, 'Riwayat'),
            NavItemData(Icons.person_rounded, 'Profil'),
          ],
        ),
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
