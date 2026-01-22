import 'package:flutter/material.dart';
import '../../screens/tabs/dashboard_tab.dart';
import '../../screens/tabs/wallet_tab.dart';
import '../../screens/tabs/history_tab.dart';
import '../../screens/tabs/profile_tab.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _currentIndex = 0;

  final List<Widget> _tabs = [
    const DashboardTab(),
    const WalletTab(),
    const HistoryTab(),
    const ProfileTab(),
  ];

  @override
  Widget build(BuildContext context) {
    // Styling bottom nav mirip web
    return Scaffold(
      body: IndexedStack(index: _currentIndex, children: _tabs),
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          border: Border(
            top: BorderSide(
              color: Theme.of(context).dividerColor.withOpacity(0.1),
            ),
          ),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.05),
              blurRadius: 10,
              offset: const Offset(0, -5),
            ),
          ],
        ),
        child: NavigationBar(
          selectedIndex: _currentIndex,
          onDestinationSelected: (i) => setState(() => _currentIndex = i),
          backgroundColor: Theme.of(context).cardColor,
          elevation: 0,
          indicatorColor:
              Colors.transparent, // Style minimalis tanpa indicator pill gede
          labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
          destinations: [
            _buildNavItem(Icons.home_outlined, Icons.home, 'Beranda', 0),
            _buildNavItem(
              Icons.account_balance_wallet_outlined,
              Icons.account_balance_wallet,
              'Dompet',
              1,
            ),
            _buildNavItem(Icons.history, Icons.history, 'Riwayat', 2),
            _buildNavItem(Icons.person_outline, Icons.person, 'Profil', 3),
          ],
        ),
      ),
    );
  }

  NavigationDestination _buildNavItem(
    IconData icon,
    IconData activeIcon,
    String label,
    int index,
  ) {
    return NavigationDestination(
      icon: Icon(icon, color: Colors.grey),
      selectedIcon: Icon(activeIcon, color: const Color(0xFF2563eb)),
      label: label,
    );
  }
}
