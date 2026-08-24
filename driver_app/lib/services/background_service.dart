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
  Timer? pasangUlang; // jadwal memasang ulang stream setelah stream mati
  int percobaanUlang = 0; // untuk menjarangkan percobaan bila gagal terus
  Position? lastPos;
  int gagalKirim = 0; // kegagalan kirim BERTURUT-TURUT (0 = sehat)
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
      // Akurasi ikut dikirim: radius area cuma 2,6 km, jadi fix yang meleset
      // ratusan meter tidak boleh dipakai memutuskan supir di dalam/luar area.
      final response = await apiService.updateLocation(
        pos.latitude,
        pos.longitude,
        accuracy: pos.accuracy,
      );

      gagalKirim = 0; // berhasil → pulih dari kegagalan sebelumnya

      final data = Map<String, dynamic>.from(response.data);
      data['latitude'] = pos.latitude;
      data['longitude'] = pos.longitude;
      service.invoke('update', data);

      // Gerbang JAM OPERASI: bila server bilang di luar jam (`tracking_open`
      // == false) DAN supir tidak sedang mengantar → hentikan layanan penuh
      // demi hemat baterai. Layanan menyala lagi saat app dibuka/di-resume
      // di dalam jam (lihat home_screen: _ensureTrackingIfWithinHours).
      final trackingOpen = response.data['tracking_open'];
      final srvStatus = response.data['status'];
      if (trackingOpen == false && srvStatus != 'ontrip') {
        _updateNotif(notifPlugin, service, {'status': 'closed'});
        posSub?.cancel();
        heartbeat?.cancel();
        service.stopSelf();
        return;
      }

      _updateNotif(notifPlugin, service, response.data);

      // Naikkan/turunkan akurasi mengikuti status dari server.
      final newMode = response.data['status'] == 'ontrip' ? 'ontrip' : 'idle';
      if (newMode != mode) {
        mode = newMode;
        subscribe(); // re-subscribe dengan setting baru
      }
    } catch (e) {
      // Diam-diam gagal adalah hal terburuk yang bisa terjadi di sini: notifikasi
      // tetap memajang stempel waktu lama, supir mengira dirinya terpantau,
      // padahal server tidak menerima apa-apa — dan sejak ada penyapu supir
      // basi, itu berarti antriannya bisa hangus. Jadi hitung kegagalannya,
      // tampilkan di notifikasi, dan beri tahu layar beranda.
      gagalKirim++;
      print("Location push failed ($gagalKirim): $e");
      _updateNotif(notifPlugin, service, {'status': 'error', 'gagal': gagalKirim});
      service.invoke('locationError', {'jenis': 'kirim', 'gagal': gagalKirim, 'pesan': '$e'});
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
    pasangUlang?.cancel();
    posSub = Geolocator.getPositionStream(locationSettings: settingsFor(mode))
        .listen(
      (pos) {
        percobaanUlang = 0; // stream sehat lagi
        push(pos);
      },
      onError: (e) {
        // Stream bisa mati di tengah jalan: GPS perangkat dimatikan, izin
        // dicabut lewat Pengaturan, atau plugin lokasi bermasalah. Dulu ini
        // cuma di-print — layanan jadi ZOMBI: notifikasi menyala, tapi tidak
        // ada satu pun titik yang terkirim lagi dan tak seorang pun tahu.
        print("Location stream error: $e");
        _updateNotif(notifPlugin, service, {'status': 'gps_error'});
        service.invoke('locationError', {'jenis': 'stream', 'pesan': '$e'});

        // Pasang ulang dengan jeda: kalau penyebabnya sementara (GPS sempat
        // dimatikan lalu dinyalakan lagi) pelacakan pulih sendiri.
        // Jarangkan percobaan bila penyebabnya ternyata permanen (izin dicabut)
        // supaya tidak jadi loop 15 detik yang menggerogoti baterai: 15, 30,
        // 45... maksimal 2 menit.
        percobaanUlang++;
        final jeda = Duration(seconds: (15 * percobaanUlang).clamp(15, 120));
        pasangUlang?.cancel();
        pasangUlang = Timer(jeda, () => subscribe());
      },
    );
  };

  service.on('stopService').listen((event) {
    posSub?.cancel();
    heartbeat?.cancel();
    pasangUlang?.cancel();
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
    // Fix awal gagal (GPS baru menyala / di dalam gedung). Bukan alasan
    // berhenti — stream di bawah tetap dipasang — tapi supir perlu tahu
    // kenapa layar beranda masih kosong.
    print("Initial fix failed: $e");
    service.invoke('locationError', {'jenis': 'fix_awal', 'pesan': '$e'});
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
  } else if (status == 'closed') {
    statusText = "Di luar jam operasi — pelacakan nonaktif";
  } else if (status == 'error') {
    // Notifikasi adalah SATU-SATUNYA tempat supir bisa melihat pelacakannya
    // bermasalah saat aplikasi tertutup — jangan diam.
    final n = data['gagal'] ?? '';
    statusText = "Lokasi gagal terkirim ${n}x — periksa koneksi | $timestamp";
  } else if (status == 'gps_error') {
    statusText = "GPS terputus — mencoba menyambung ulang | $timestamp";
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
