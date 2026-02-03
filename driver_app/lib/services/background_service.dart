import 'dart:async';
import 'dart:ui';
import 'package:driver_app/services/api_service.dart';
import 'package:flutter_background_service/flutter_background_service.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:geolocator/geolocator.dart';
import 'package:intl/intl.dart';

Future<void> initializeBackgroundService() async {
  final service = FlutterBackgroundService();

  // 1. Setup Notification Channel (Android)
  const AndroidNotificationChannel channel = AndroidNotificationChannel(
    'my_foreground', // id
    'Location Tracking', // title
    description: 'This channel is used for location tracking.',
    importance: Importance.low, // Low = no sound
  );

  final FlutterLocalNotificationsPlugin flutterLocalNotificationsPlugin =
      FlutterLocalNotificationsPlugin();

  await flutterLocalNotificationsPlugin
      .resolvePlatformSpecificImplementation<
        AndroidFlutterLocalNotificationsPlugin
      >()
      ?.createNotificationChannel(channel);

  // 2. Configure Service
  await service.configure(
    androidConfiguration: AndroidConfiguration(
      onStart: onStart,
      autoStart: false, // Start manually when Online
      isForegroundMode: true,
      notificationChannelId: 'my_foreground',
      initialNotificationTitle: 'Taksi POS Driver',
      initialNotificationContent: 'Menyiapkan layanan lokasi...',
      foregroundServiceNotificationId: 888,
    ),
    iosConfiguration: IosConfiguration(
      autoStart: false,
      onForeground: onStart,
      onBackground: onIosBackground,
    ),
  );
}

// iOS Placeholder
@pragma('vm:entry-point')
Future<bool> onIosBackground(ServiceInstance service) async {
  return true;
}

// Main Background Logic
@pragma('vm:entry-point')
void onStart(ServiceInstance service) async {
  DartPluginRegistrant.ensureInitialized();

  final ApiService apiService = ApiService();
  final FlutterLocalNotificationsPlugin flutterLocalNotificationsPlugin =
      FlutterLocalNotificationsPlugin();

  // Timer reference
  Timer? timer;

  service.on('stopService').listen((event) {
    service.stopSelf();
    timer?.cancel();
  });

  print("Background Service: Started");

  // Immediate Update
  _performLocationUpdate(apiService, flutterLocalNotificationsPlugin, service);

  // Periodic Update (30s)
  timer = Timer.periodic(const Duration(seconds: 30), (timer) async {
    try {
      await _performLocationUpdate(
        apiService,
        flutterLocalNotificationsPlugin,
        service,
      );
    } catch (e) {
      print("Background Loop Error: $e");
    }
  });
}

Future<void> _performLocationUpdate(
  ApiService apiService,
  FlutterLocalNotificationsPlugin notifPlugin,
  ServiceInstance service,
) async {
  try {
    // 1. Get Permission Check (Optional, usually assumed granted)
    // 2. Get Location
    Position position = await Geolocator.getCurrentPosition(
      desiredAccuracy: LocationAccuracy.high,
    );

    // 3. Send to API
    final response = await apiService.updateLocation(
      position.latitude,
      position.longitude,
    );

    // 4. Update UI (if app is open)
    service.invoke('update', response.data);

    // 5. Update Notification
    final timestamp = DateFormat('HH:mm:ss').format(DateTime.now());

    // Status text for notification
    String statusText = "Lokasi terupdate: $timestamp";
    if (response.data['status'] == 'standby') {
      statusText =
          "Standby (Antrian #${response.data['line_number'] ?? '?'}) | $timestamp";
    } else if (response.data['status'] == 'offline') {
      statusText = "Offline (Diluar Area) | $timestamp";
    }

    if (service is AndroidServiceInstance) {
      if (await service.isForegroundService()) {
        notifPlugin.show(
          888,
          'Taksi POS Driver (Aktif)',
          statusText,
          const NotificationDetails(
            android: AndroidNotificationDetails(
              'my_foreground',
              'Location Tracking',
              icon:
                  'ic_bg_service_small', // Make sure this icon exists or use default
              ongoing: true,
              importance: Importance.low,
            ),
          ),
        );
      }
    }

    print("Background Update SUCCESS: $timestamp");
  } catch (e) {
    print("Background Update FAILED: $e");
    if (service is AndroidServiceInstance) {
      // service.setAsForegroundService(); // Ensure it stays alive
    }
  }
}
