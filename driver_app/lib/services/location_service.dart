import 'dart:async';
import 'dart:ui';
import 'package:dio/dio.dart';
import 'package:flutter_background_service/flutter_background_service.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:geolocator/geolocator.dart';
import '../config/api_constants.dart';

class LocationService {
  static Future<void> initializeService() async {
    final service = FlutterBackgroundService();

    // Notifikasi Channel untuk Android
    const AndroidNotificationChannel channel = AndroidNotificationChannel(
      'my_foreground', // id
      'MY FOREGROUND SERVICE', // title
      description:
          'This channel is used for important notifications.', // description
      importance: Importance.low, // low agar tidak bunyi terus
    );

    final FlutterLocalNotificationsPlugin flutterLocalNotificationsPlugin =
        FlutterLocalNotificationsPlugin();

    await flutterLocalNotificationsPlugin
        .resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin
        >()
        ?.createNotificationChannel(channel);

    await service.configure(
      androidConfiguration: AndroidConfiguration(
        // this will be executed when app is in foreground or background in separated isolate
        onStart: onStart,

        // auto start service
        autoStart: false,
        isForegroundMode: true,

        notificationChannelId: 'my_foreground',
        initialNotificationTitle: 'Taksi Driver Service',
        initialNotificationContent: 'Menunggu koneksi...',
        foregroundServiceNotificationId: 888,
      ),
      iosConfiguration: IosConfiguration(
        autoStart: false,
        onForeground: onStart,
        onBackground: onIosBackground,
      ),
    );
  }

  @pragma('vm:entry-point')
  static Future<bool> onIosBackground(ServiceInstance service) async {
    return true;
  }

  @pragma('vm:entry-point')
  static void onStart(ServiceInstance service) async {
    // Only available for flutter 3.0.0 and later
    DartPluginRegistrant.ensureInitialized();

    final FlutterLocalNotificationsPlugin flutterLocalNotificationsPlugin =
        FlutterLocalNotificationsPlugin();

    // Storage & Dio di Isolate terpisah
    const storage = FlutterSecureStorage();
    final dio = Dio(
      BaseOptions(
        baseUrl: ApiConstants.baseUrl,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
      ),
    );

    // Listen to stop action
    service.on('stopService').listen((event) {
      service.stopSelf();
    });

    // Timer untuk kirim lokasi setiap 10 detik (atau gunakan Geolocator Stream)
    // Stream lebih akurat untuk pergerakan
    final StreamSubscription<Position> positionStream =
        Geolocator.getPositionStream(
          locationSettings: const LocationSettings(
            accuracy: LocationAccuracy.high,
            distanceFilter: 10, // Update tiap 10 meter
          ),
        ).listen((Position position) async {
          // 1. Update Notifikasi (Feedback Visual)
          if (service is AndroidServiceInstance) {
            if (await service.isForegroundService()) {
              flutterLocalNotificationsPlugin.show(
                888,
                'Taksi Driver Service',
                'Lokasi: ${position.latitude}, ${position.longitude}',
                const NotificationDetails(
                  android: AndroidNotificationDetails(
                    'my_foreground',
                    'MY FOREGROUND SERVICE',
                    icon: '@mipmap/ic_launcher',
                    ongoing: true,
                  ),
                ),
              );
            }
          }

          // 2. Kirim ke Server
          try {
            final token = await storage.read(key: 'auth_token');
            if (token != null) {
              await dio.post(
                ApiConstants.location,
                options: Options(headers: {'Authorization': 'Bearer $token'}),
                data: {
                  'latitude': position.latitude,
                  'longitude': position.longitude,
                },
              );

              print(
                'Lokasi Terkirim: ${position.latitude}, ${position.longitude}',
              );
            } else {
              // Token expired atau logout
              service.stopSelf();
            }
          } catch (e) {
            print('Gagal kirim lokasi: $e');
          }
        });

    // Check listener
    service.on('stopStream').listen((event) {
      positionStream.cancel();
    });
  }
}
