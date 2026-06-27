import 'package:flutter/material.dart';
import 'home_screen.dart';
import 'wallet_screen.dart';
import 'history_screen.dart';
import 'profile_screen.dart';
import '../theme/app_colors.dart';
import '../widgets/app_bottom_nav.dart';

class MainScreen extends StatefulWidget {
  const MainScreen({super.key});

  @override
  State<MainScreen> createState() => _MainScreenState();
}

class _MainScreenState extends State<MainScreen> {
  int _currentIndex = 0;

  final List<Widget> _screens = const [
    HomeScreen(),
    WalletScreen(),
    HistoryScreen(),
    ProfileScreen(),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      body: IndexedStack(index: _currentIndex, children: _screens),
      bottomNavigationBar: AppBottomNav(
        currentIndex: _currentIndex,
        onTap: (index) => setState(() => _currentIndex = index),
        items: const [
          NavItemData(Icons.dashboard_rounded, 'Beranda'),
          NavItemData(Icons.account_balance_wallet_rounded, 'Dompet'),
          NavItemData(Icons.history_rounded, 'Riwayat'),
          NavItemData(Icons.person_rounded, 'Profil'),
        ],
      ),
    );
  }
}
