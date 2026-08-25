import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
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
import 'widgets/offline_banner.dart';

// Key Global untuk Navigasi tanpa Context
final GlobalKey<NavigatorState> navigatorKey = GlobalKey<NavigatorState>();

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Kunci potret. Seluruh tata letak (dock nav, form pemesanan, struk) dirancang
  // untuk potret; mode melintang hanya membuatnya melar tanpa manfaat.
  // portraitDown tidak diikutsertakan: terbalik 180 derajat tidak ada gunanya
  // di ponsel dan justru mengagetkan kalau HP tergeletak.
  await SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp]);

  try {
    await Firebase.initializeApp(); // Init Firebase
    print("Firebase Initialized Successfully");
  } catch (e) {
    print("Firebase Initialization Failed: $e");
    // Lanjutkan loading app meski Firebase gagal, agar tidak stuck black screen
  }

  // Init Background Service (tidak didukung di web)
  if (!kIsWeb) {
    await initializeBackgroundService();
  }

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
      builder: (context, child) =>
          GlobalOfflineWrapper(child: child ?? const SizedBox.shrink()),
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

    // 1. Init Notification Service (FCM tidak tersedia di web tanpa FirebaseOptions)
    if (!kIsWeb) {
      NotificationService().initialize(ApiService(), (payload) {
        // Handle Navigation on Tap
        print("Navigate by Notification payload: $payload");

        // Hanya payload yang dikenali yang memicu navigasi.
        if (payload != 'new_order' && payload != 'deposit') return;

        // Shell dipilih berdasarkan PERAN. Sebelumnya semua notifikasi membuka
        // MainScreen — shell supir — sehingga CSO yang menekan notifikasi
        // setoran akan dilempar ke layar yang bukan miliknya.
        final ctx = navigatorKey.currentContext;
        final role = ctx != null
            ? Provider.of<AuthProvider>(ctx, listen: false).role
            : null;

        navigatorKey.currentState?.pushAndRemoveUntil(
          MaterialPageRoute(
            builder: (context) =>
                role == 'cso' ? const CsoMainScreen() : const MainScreen(),
          ),
          (route) => false, // Hapus stack lama
        );
      });
    }

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
