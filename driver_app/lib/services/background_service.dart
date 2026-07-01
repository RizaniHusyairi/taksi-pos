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

// Main Background Logic — stream lokasi hemat-daya + adaptif per status + heartbeat.
// Ganti polling Timer 30 dtk (boros: fix akurasi-tinggi tiap tick walau diam)
// dengan stream ber-distanceFilter: hanya kirim saat BERGERAK, plus heartbeat
// ringan saat diam agar server & peta CSO tahu supir masih ada.
@pragma('vm:entry-point')
void onStart(ServiceInstance service) async {
  DartPluginRegistrant.ensureInitialized();

  final ApiService apiService = ApiService();
  final FlutterLocalNotificationsPlugin notifPlugin =
      FlutterLocalNotificationsPlugin();

  StreamSubscription<Position>? posSub;
  Timer? heartbeat;
  Position? lastPos;
  // Mode akurasi: 'ontrip' (mengantar → mulus & sering) vs 'idle' (standby/offline → hemat).
  String mode = 'idle';

  // Setting stream berbeda per mode: on-trip presisi & sering; idle irit baterai.
  LocationSettings settingsFor(String m) {
    if (m == 'ontrip') {
      return AndroidSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 10, // meter
        intervalDuration: const Duration(seconds: 4),
      );
    }
    return AndroidSettings(
      accuracy: LocationAccuracy.medium,
      distanceFilter: 40, // meter — supir parkir di antrian nyaris tak kirim data
      intervalDuration: const Duration(seconds: 15),
    );
  }

  // Dua closure saling-memanggil → dideklarasikan sebagai variabel `late`
  // (Dart tak mengizinkan forward-reference antar fungsi lokal biasa).
  late final Future<void> Function(Position) push;
  late final void Function() subscribe;

  // Kirim satu posisi ke server + update UI/notif + adaptasi mode + reset heartbeat.
  push = (Position pos) async {
    lastPos = pos;
    try {
      final response =
          await apiService.updateLocation(pos.latitude, pos.longitude);

      final data = Map<String, dynamic>.from(response.data);
      data['latitude'] = pos.latitude;
      data['longitude'] = pos.longitude;
      service.invoke('update', data);

      _updateNotif(notifPlugin, service, response.data);

      // Naikkan/turunkan akurasi mengikuti status dari server.
      final newMode = response.data['status'] == 'ontrip' ? 'ontrip' : 'idle';
      if (newMode != mode) {
        mode = newMode;
        subscribe(); // re-subscribe dengan setting baru
      }
    } catch (e) {
      print("Location push failed: $e");
    }

    // Heartbeat: saat DIAM (stream tak emit), tetap kirim posisi terakhir tiap
    // 75 dtk agar server & peta CSO tak menandai supir "basi" (ambang 120 dtk).
    heartbeat?.cancel();
    heartbeat = Timer(const Duration(seconds: 75), () {
      if (lastPos != null) push(lastPos!);
    });
  };

  subscribe = () {
    posSub?.cancel();
    posSub = Geolocator.getPositionStream(locationSettings: settingsFor(mode))
        .listen(
      (pos) => push(pos),
      onError: (e) => print("Location stream error: $e"),
    );
  };

  service.on('stopService').listen((event) {
    posSub?.cancel();
    heartbeat?.cancel();
    service.stopSelf();
  });

  print("Background Service: Started (stream mode)");

  // Fix awal cepat, lalu jalankan stream hemat-daya.
  try {
    final first = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(accuracy: LocationAccuracy.high),
    );
    await push(first);
  } catch (e) {
    print("Initial fix failed: $e");
  }
  subscribe();
}

// Update notifikasi foreground service (ongoing, tanpa suara).
void _updateNotif(
  FlutterLocalNotificationsPlugin notifPlugin,
  ServiceInstance service,
  dynamic data,
) async {
  final timestamp = DateFormat('HH:mm:ss').format(DateTime.now());
  final status = data['status'];
  String statusText = "Lokasi terupdate: $timestamp";
  if (status == 'standby') {
    statusText = "Standby (Antrian #${data['line_number'] ?? '?'}) | $timestamp";
  } else if (status == 'offline') {
    statusText = "Offline (Diluar Area) | $timestamp";
  } else if (status == 'ontrip') {
    statusText = "Mengantar penumpang | $timestamp";
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
            icon: 'ic_bg_service_small',
            ongoing: true,
            importance: Importance.low,
          ),
        ),
      );
    }
  }
}
