import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:driver_app/services/api_service.dart';
import 'package:flutter/material.dart';

// Top-level function for background handling
@pragma('vm:entry-point')
Future<void> _firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();
  print("Handling a background message: ${message.messageId}");
}

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
      print('User granted permission');
    } else {
      print('User declined or has not accepted permission');
      return;
    }

    this.onOrderReceived = onNavigate;

    // 2. Initialize Local Notifications (For Foreground)
    const AndroidInitializationSettings initializationSettingsAndroid =
        AndroidInitializationSettings('@mipmap/ic_launcher');

    final InitializationSettings initializationSettings =
        InitializationSettings(android: initializationSettingsAndroid);

    await _localNotifications.initialize(
      initializationSettings,
      onDidReceiveNotificationResponse: (NotificationResponse response) {
        // Handle tap on foreground notification
        print("Tapped on Local Notification payload: ${response.payload}");
        if (response.payload != null) {
          onNavigate(response.payload!); // Trigger navigation
        }
      },
    );

    // 3. Register Background Handler
    FirebaseMessaging.onBackgroundMessage(_firebaseMessagingBackgroundHandler);

    // 4. Handle Foreground Messages
    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      print('Got a message whilst in the foreground!');
      print('Message data: ${message.data}');

      RemoteNotification? notification = message.notification;
      AndroidNotification? android = message.notification?.android;

      // Show Local Notification
      if (notification != null && android != null) {
        _localNotifications.show(
          notification.hashCode,
          notification.title,
          notification.body,
          const NotificationDetails(
            android: AndroidNotificationDetails(
              'high_importance_channel', // id
              'High Importance Notifications', // title
              importance: Importance.max,
              priority: Priority.high,
              playSound: true,
            ),
          ),
          payload:
              message.data['type'] ?? 'default', // Pass payload for navigation
        );
      }
    });

    // 5. Handle Tap on Background/Terminated Notification
    // A. App opened from Terminated state
    FirebaseMessaging.instance.getInitialMessage().then((
      RemoteMessage? message,
    ) {
      if (message != null) {
        print("App opened from Terminated state: ${message.data}");
        // Add delay to ensure context is ready or store pending navigation
        Future.delayed(Duration(seconds: 2), () {
          onNavigate(message.data['type'] ?? 'default');
        });
      }
    });

    // B. App opened from Background state
    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
      print("App opened from Background state: ${message.data}");
      onNavigate(message.data['type'] ?? 'default');
    });

    // 6. Get Token & Update Backend
    String? token = await _firebaseMessaging.getToken();
    print("FCM Token: $token");
    if (token != null) {
      try {
        await apiService.updateFcmToken(token);
        print("FCM Token sent to backend");
      } catch (e) {
        print("Failed to send FCM Token: $e");
      }
    }

    // Listen for Token Refresh
    _firebaseMessaging.onTokenRefresh.listen((newToken) async {
      try {
        await apiService.updateFcmToken(newToken);
      } catch (err) {
        print("Error updating token: $err");
      }
    });
  }
}
