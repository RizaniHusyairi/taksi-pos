import 'dart:io';
import 'package:dio/dio.dart';
import 'package:dio/io.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class ApiService {
  // Use 10.0.2.2 for Android Emulator to access localhost
  // Use your machine's IP (e.g., 10.49.92.29) if testing on physical device
  static const String baseUrl = 'https://kaj.aptpairport.id/api';

  late Dio _dio;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  ApiService() {
    _dio = Dio(
      BaseOptions(
        baseUrl: baseUrl,
        connectTimeout: const Duration(seconds: 30),
        receiveTimeout: const Duration(seconds: 30),
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      ),
    );

    // Bypass SSL Certificate Verifications for HandshakeException
    _dio.httpClientAdapter = IOHttpClientAdapter(
      createHttpClient: () {
        final client = HttpClient();
        client.badCertificateCallback = 
            (X509Certificate cert, String host, int port) => true;
        return client;
      },
    );

    // Add interceptor to attach token
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _storage.read(key: 'auth_token');
          if (token != null) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          return handler.next(options);
        },
        onError: (DioException e, handler) {
          // Handle 401 Unauthorized globally if needed
          if (e.response?.statusCode == 401) {
            // Trigger logout or storage clear logic here?
          }
          return handler.next(e);
        },
      ),
    );
  }

  // --- Auth Endpoints ---

  Future<Response> login(String username, String password) async {
    return await _dio.post(
      '/login',
      data: {'username': username, 'password': password},
    );
  }

  Future<Response> logout() async {
    return await _dio.post('/logout');
  }

  // --- Driver Endpoints ---

  Future<Response> getProfile() async {
    return await _dio.get('/driver/profile');
  }

  Future<Response> updateLocation(double lat, double lng) async {
    return await _dio.post(
      '/driver/location',
      data: {'latitude': lat, 'longitude': lng},
    );
  }

  Future<Response> setStatus(
    String action, {
    String? reason,
    String? manualDestination,
    int? manualPrice,
    double? latitude,
    double? longitude,
  }) async {
    final Map<String, dynamic> data = {'action': action};
    if (reason != null) data['reason'] = reason;
    if (manualDestination != null) {
      data['manual_destination'] = manualDestination;
    }
    if (manualPrice != null) data['manual_price'] = manualPrice;
    if (latitude != null) data['latitude'] = latitude;
    if (longitude != null) data['longitude'] = longitude;

    return await _dio.post('/driver/status', data: data);
  }

  Future<Response> getBalance() async {
    return await _dio.get('/driver/balance');
  }

  Future<Response> getWithdrawalHistory() async {
    return await _dio.get('/driver/withdrawals');
  }

  Future<Response> requestWithdrawal() async {
    return await _dio.post('/driver/withdrawals');
  }

  Future<Response> getTripHistory() async {
    return await _dio.get('/driver/history');
  }

  Future<Response> startBooking(int bookingId) async {
    return await _dio.post('/driver/bookings/$bookingId/start');
  }

  Future<Response> completeBooking(int bookingId) async {
    return await _dio.post('/driver/bookings/$bookingId/complete');
  }

  Future<Response> updateBankDetails(String accountNumber) async {
    return await _dio.post(
      '/driver/bank-details',
      data: {'account_number': accountNumber},
    );
  }

  Future<Response> updateProfile(Map<String, dynamic> data) async {
    return await _dio.post('/driver/profile/update', data: data);
  }

  Future<Response> changePassword(
    String currentPassword,
    String newPassword,
  ) async {
    return await _dio.post(
      '/driver/change-password',
      data: {
        'current_password': currentPassword,
        'new_password': newPassword,
        'new_password_confirmation': newPassword,
      },
    );
  }

  Future<Response> updateFcmToken(String token) async {
    return await _dio.post(
      '/driver/update-fcm-token',
      data: {'fcm_token': token},
    );
  }
}
