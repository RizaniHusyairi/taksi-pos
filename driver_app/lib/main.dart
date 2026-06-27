import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'providers/auth_provider.dart';
import 'screens/login_screen.dart';
import 'screens/main_screen.dart';
import 'screens/cso/cso_main_screen.dart';
import 'screens/splash_screen.dart';
import 'theme/app_theme.dart';
import 'package:firebase_core/firebase_core.dart';
import 'services/notification_service.dart';
import 'services/api_service.dart';
import 'services/background_service.dart';

// Key Global untuk Navigasi tanpa Context
final GlobalKey<NavigatorState> navigatorKey = GlobalKey<NavigatorState>();

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  try {
    await Firebase.initializeApp(); // Init Firebase
    print("Firebase Initialized Successfully");
  } catch (e) {
    print("Firebase Initialization Failed: $e");
    // Lanjutkan loading app meski Firebase gagal, agar tidak stuck black screen
  }

  // Init Background Service
  await initializeBackgroundService();

  runApp(
    MultiProvider(
      providers: [
        Provider(create: (_) => ApiService()),
        ChangeNotifierProvider(create: (_) => AuthProvider()),
      ],
      child: const DriverApp(),
    ),
  );
}

class DriverApp extends StatelessWidget {
  const DriverApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Taxi Angkasa Jaya Driver',
      debugShowCheckedModeBanner: false,
      navigatorKey: navigatorKey, // Pasang navigatorKey
      theme: AppTheme.light(context),
      home: const AuthWrapper(),
    );
  }
}

class AuthWrapper extends StatefulWidget {
  const AuthWrapper({super.key});

  @override
  State<AuthWrapper> createState() => _AuthWrapperState();
}

class _AuthWrapperState extends State<AuthWrapper> {
  bool _showSplash = true;

  @override
  void initState() {
    super.initState();

    // 1. Init Notification Service
    NotificationService().initialize(ApiService(), (payload) {
      // Handle Navigation on Tap
      print("Navigate by Notification payload: $payload");
      if (payload == 'new_order') {
        // Navigasi ke MainScreen (Home)
        // Karena kita pakai Global Key, kita bisa navigasi dari mana saja
        navigatorKey.currentState?.pushAndRemoveUntil(
          MaterialPageRoute(builder: (context) => const MainScreen()),
          (route) => false, // Hapus stack lama
        );
      }
    });

    // 2. Check login status
    Future.microtask(
      () =>
          Provider.of<AuthProvider>(context, listen: false).checkLoginStatus(),
    );

    // 3. Tampilkan splash beranimasi sejenak saat pertama buka
    Future.delayed(const Duration(milliseconds: 2000), () {
      if (mounted) setState(() => _showSplash = false);
    });
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 500),
      child: _showSplash
          ? const SplashScreen()
          : Consumer<AuthProvider>(
              builder: (context, auth, _) {
                if (!auth.isAuthenticated) {
                  return const LoginScreen();
                }
                // Arahkan ke shell sesuai peran pengguna.
                return auth.isCso ? const CsoMainScreen() : const MainScreen();
              },
            ),
    );
  }
}
