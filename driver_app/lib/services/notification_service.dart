import 'dart:async';
import 'dart:typed_data';
import 'dart:ui' show DartPluginRegistrant;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart' show debugPrint;
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:driver_app/services/api_service.dart';

// ---------------------------------------------------------------------------
// Kanal & id notifikasi
// ---------------------------------------------------------------------------

/// Kanal umum (setoran, info). Id-nya dipakai juga oleh FcmService di server.
const String kHighImportanceChannel = 'high_importance_channel';

/// Order baru untuk supir: berdering seperti ALARM sampai ditanggapi.
///
/// Di lapangan supir biasanya keluar mobil dan mengobrol — HP di saku, kadang
/// dalam mode getar. Jalur suara alarm tetap berbunyi pada mode getar dan
/// memakai volume alarm yang jarang dikecilkan orang.
const String kOrderAlarmChannel = 'order_alarm_channel';

/// Peringatan pra-giliran ("bersiap di dekat mobil"). Id dipakai FcmService.
const String kQueueHeadsUpChannel = 'queue_heads_up_channel';

/// Id tetap untuk alarm order: satu supir hanya punya satu order aktif, jadi
/// notifikasi baru cukup menimpa yang lama dan mudah dibatalkan.
const int kOrderAlarmNotificationId = 7001;

/// Id aksi tombol "SAYA JEMPUT" di notifikasi.
const String kPickupActionId = 'pickup';

const AndroidNotificationChannel _highImportance = AndroidNotificationChannel(
  kHighImportanceChannel,
  'Order & Notifikasi Penting',
  description: 'Notifikasi order baru dan info penting lainnya.',
  importance: Importance.max,
  playSound: true,
  enableVibration: true,
);

final AndroidNotificationChannel _orderAlarm = AndroidNotificationChannel(
  kOrderAlarmChannel,
  'Order Masuk (Dering)',
  description:
      'Berdering seperti alarm saat ada order, sampai Anda menekan SAYA JEMPUT.',
  importance: Importance.max,
  playSound: true,
  enableVibration: true,
  vibrationPattern: Int64List.fromList([0, 900, 500, 900, 500, 900]),
  audioAttributesUsage: AudioAttributesUsage.alarm,
);

final AndroidNotificationChannel _queueHeadsUp = AndroidNotificationChannel(
  kQueueHeadsUpChannel,
  'Giliran Antrian',
  description: 'Getar saat giliran Anda sudah dekat.',
  importance: Importance.high,
  playSound: true,
  enableVibration: true,
  vibrationPattern: Int64List.fromList([0, 400, 250, 400]),
);

// ---------------------------------------------------------------------------
// Handler top-level (isolate latar belakang)
// ---------------------------------------------------------------------------

/// FCM saat aplikasi di latar / tertutup. Order baru dikirim server sebagai
/// pesan DATA saja (tanpa blok `notification`), supaya aplikasi sendiri yang
/// menampilkan dering alarm, layar penuh, dan tombol "SAYA JEMPUT" — hal yang
/// tidak bisa dilakukan notifikasi bawaan sistem.
@pragma('vm:entry-point')
Future<void> _firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();
  if (message.data['type'] == 'new_order') {
    final plugin = FlutterLocalNotificationsPlugin();
    await _initPlugin(plugin);
    await _showOrderAlarm(plugin, message.data);
  }
}

/// Tombol "SAYA JEMPUT" ditekan saat aplikasi TIDAK berjalan. Dijalankan di
/// isolate terpisah, jadi semua dependensi dibuat ulang di sini.
@pragma('vm:entry-point')
Future<void> notificationTapBackground(NotificationResponse response) async {
  DartPluginRegistrant.ensureInitialized();
  if (response.actionId != kPickupActionId) return;
  await _confirmPickupFromNotification(response.payload);
}

Future<void> _confirmPickupFromNotification(
  String? payload, {
  FlutterLocalNotificationsPlugin? initializedPlugin,
}) async {
  final bookingId = _bookingIdFromPayload(payload);
  if (bookingId == null) return;
  try {
    await ApiService().confirmPickup(bookingId, source: 'notification');
  } catch (e) {
    // Gagal (sinyal buruk): tampilkan lagi supaya supir bisa mencoba ulang —
    // lebih baik berdering lagi daripada karcis diam-diam tidak terbit.
    debugPrint('Konfirmasi jemput dari notifikasi gagal: $e');
    // Di isolate utama plugin sudah terinisialisasi dengan callback tap;
    // inisialisasi ulang akan menimpanya.
    final plugin = initializedPlugin ?? FlutterLocalNotificationsPlugin();
    if (initializedPlugin == null) await _initPlugin(plugin);
    await _showOrderAlarm(plugin, {
      'booking_id': '$bookingId',
      'title': 'Gagal mengirim — coba lagi',
      'body': 'Periksa sinyal, lalu tekan SAYA JEMPUT sekali lagi.',
    });
  }
}

/// Payload alarm order: `new_order:<booking_id>`.
int? _bookingIdFromPayload(String? payload) {
  if (payload == null || !payload.startsWith('new_order:')) return null;
  return int.tryParse(payload.substring('new_order:'.length));
}

Future<void> _initPlugin(
  FlutterLocalNotificationsPlugin plugin, {
  void Function(NotificationResponse)? onResponse,
}) async {
  await plugin.initialize(
    const InitializationSettings(
      android: AndroidInitializationSettings('@mipmap/ic_launcher'),
    ),
    onDidReceiveNotificationResponse: onResponse,
    onDidReceiveBackgroundNotificationResponse: notificationTapBackground,
  );
  final android = plugin.resolvePlatformSpecificImplementation<
      AndroidFlutterLocalNotificationsPlugin>();
  await android?.createNotificationChannel(_highImportance);
  await android?.createNotificationChannel(_orderAlarm);
  await android?.createNotificationChannel(_queueHeadsUp);
}

Future<void> _showOrderAlarm(
  FlutterLocalNotificationsPlugin plugin,
  Map<String, dynamic> data,
) async {
  final bookingId = data['booking_id']?.toString() ?? '';
  final title = data['title']?.toString() ?? 'Order Baru Masuk! 🚖';
  final body = data['body']?.toString() ??
      'Tujuan: ${data['destination'] ?? '-'} · Tarif Rp ${data['fare'] ?? '-'}';

  await plugin.show(
    kOrderAlarmNotificationId,
    title,
    body,
    NotificationDetails(
      android: AndroidNotificationDetails(
        kOrderAlarmChannel,
        _orderAlarm.name,
        channelDescription: _orderAlarm.description,
        importance: Importance.max,
        priority: Priority.max,
        category: AndroidNotificationCategory.call,
        visibility: NotificationVisibility.public,
        fullScreenIntent: true,
        audioAttributesUsage: AudioAttributesUsage.alarm,
        vibrationPattern: _orderAlarm.vibrationPattern,
        ongoing: true,
        autoCancel: false,
        // FLAG_INSISTENT (4): suara & getar diulang terus sampai notifikasi
        // ditanggapi atau dibatalkan.
        additionalFlags: Int32List.fromList([4]),
        // Berhenti sendiri setelah 3 menit; saat itu CSO sudah melihat kartu
        // oranye "Telepon / Ganti Supir".
        timeoutAfter: 180000,
        ticker: 'Order baru masuk',
        styleInformation: BigTextStyleInformation(body, contentTitle: title),
        actions: const [
          AndroidNotificationAction(
            kPickupActionId,
            'SAYA JEMPUT',
            showsUserInterface: false,
            cancelNotification: true,
          ),
        ],
      ),
    ),
    payload: 'new_order:$bookingId',
  );
}

// ---------------------------------------------------------------------------
// Layanan (isolate utama)
// ---------------------------------------------------------------------------

class NotificationService {
  static final NotificationService _instance = NotificationService._internal();

  factory NotificationService() {
    return _instance;
  }

  NotificationService._internal();

  final FirebaseMessaging _firebaseMessaging = FirebaseMessaging.instance;
  final FlutterLocalNotificationsPlugin _localNotifications =
      FlutterLocalNotificationsPlugin();

  // Callback for navigation
  Function(String)? onOrderReceived;

  /// Peristiwa push yang perlu ditanggapi layar yang sedang terbuka tanpa
  /// berpindah halaman — mis. `ticket_ready` untuk kartu tunggu CSO, atau
  /// `pickup_confirmed` agar kartu order supir langsung berubah.
  final StreamController<Map<String, String>> _events =
      StreamController<Map<String, String>>.broadcast();
  Stream<Map<String, String>> get events => _events.stream;

  Future<void> initialize(
    ApiService apiService,
    Function(String) onNavigate,
  ) async {
    // 1. Request Permission
    NotificationSettings settings = await _firebaseMessaging.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );

    if (settings.authorizationStatus == AuthorizationStatus.authorized) {
      debugPrint('User granted permission');
    } else {
      debugPrint('User declined or has not accepted permission');
      return;
    }

    onOrderReceived = onNavigate;

    // 2. Local notifications + kanal
    await _initPlugin(
      _localNotifications,
      onResponse: (NotificationResponse response) async {
        // Tombol "SAYA JEMPUT" saat aplikasi masih hidup (depan / latar).
        if (response.actionId == kPickupActionId) {
          await _confirmPickupFromNotification(
            response.payload,
            initializedPlugin: _localNotifications,
          );
          _events.add({'type': 'pickup_confirmed'});
          return;
        }
        final payload = response.payload;
        if (payload == null) return;
        onNavigate(payload.startsWith('new_order') ? 'new_order' : payload);
      },
    );

    // Aplikasi dibuka oleh notifikasi (termasuk layar penuh alarm order)
    // dari keadaan tertutup.
    final launch = await _localNotifications.getNotificationAppLaunchDetails();
    final launchPayload = launch?.notificationResponse?.payload;
    if ((launch?.didNotificationLaunchApp ?? false) && launchPayload != null) {
      Future.delayed(const Duration(seconds: 2), () {
        onNavigate(
          launchPayload.startsWith('new_order') ? 'new_order' : launchPayload,
        );
      });
    }

    // 3. Register Background Handler
    FirebaseMessaging.onBackgroundMessage(_firebaseMessagingBackgroundHandler);

    // 4. Pesan saat aplikasi di depan
    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      final type = message.data['type']?.toString() ?? 'default';
      _events.add(
        message.data.map((k, v) => MapEntry(k, v.toString())),
      );

      if (type == 'new_order') {
        _showOrderAlarm(_localNotifications, message.data);
        // Supir sedang memegang HP: langsung tampilkan layar order.
        onNavigate('new_order');
        return;
      }

      final notification = message.notification;
      if (notification == null) return;

      final channel =
          type == 'queue_heads_up' ? _queueHeadsUp : _highImportance;
      _localNotifications.show(
        notification.hashCode,
        notification.title,
        notification.body,
        NotificationDetails(
          android: AndroidNotificationDetails(
            channel.id,
            channel.name,
            importance: channel.importance,
            priority: Priority.high,
            playSound: true,
            enableVibration: true,
            vibrationPattern: channel.vibrationPattern,
            icon: '@mipmap/ic_launcher',
            styleInformation: BigTextStyleInformation(
              notification.body ?? '',
              contentTitle: notification.title,
            ),
          ),
        ),
        payload: type,
      );
    });

    // 5. Handle Tap on Background/Terminated Notification
    FirebaseMessaging.instance.getInitialMessage().then((
      RemoteMessage? message,
    ) {
      if (message != null) {
        Future.delayed(const Duration(seconds: 2), () {
          onNavigate(message.data['type'] ?? 'default');
        });
      }
    });

    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
      onNavigate(message.data['type'] ?? 'default');
    });

    // 6. Get Token (Don't send yet, AuthProvider will do it)
    await _firebaseMessaging.getToken();

    // Listen for Token Refresh
    _firebaseMessaging.onTokenRefresh.listen((newToken) async {
      try {
        await apiService.updateFcmToken(newToken);
      } catch (err) {
        debugPrint("Error updating token: $err");
      }
    });
  }

  Future<String?> getFcmToken() async {
    return await _firebaseMessaging.getToken();
  }

  /// Hentikan dering order (supir sudah menekan SAYA JEMPUT di aplikasi).
  Future<void> cancelOrderAlarm() async {
    await _localNotifications.cancel(kOrderAlarmNotificationId);
  }

  /// Minta sekali izin layar penuh (Android 14+ tidak memberikannya otomatis
  /// untuk aplikasi non-telepon). Tanpa izin ini alarm tetap berdering, hanya
  /// tidak menyalakan layar yang terkunci.
  ///
  /// [explain] menampilkan alasan ke supir sebelum dibawa ke Pengaturan;
  /// kembalikan false bila supir memilih nanti (akan ditanya lagi lain kali).
  Future<void> askFullScreenPermissionOnce(
    Future<bool> Function() explain,
  ) async {
    const storage = FlutterSecureStorage();
    const key = 'full_screen_permission_asked';
    if (await storage.read(key: key) == '1') return;
    if (!await explain()) return;
    await storage.write(key: key, value: '1');
    await _localNotifications
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.requestFullScreenIntentPermission();
  }
}
