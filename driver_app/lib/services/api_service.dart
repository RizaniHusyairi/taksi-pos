import 'dart:io';
import 'package:dio/dio.dart';
import 'package:dio/io.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class ApiService {
  // Testing via USB: phone reaches the PC server through `adb reverse tcp:8000 tcp:8000`,
  // so localhost on the phone tunnels to the PC. No Wi-Fi/firewall needed.
  //   LAN alternative: http://<PC-LAN-IP>:8000/api  (needs server on 0.0.0.0 + firewall open)
  //   Production:      https://kaj.aptpairport.id/api
  static const String baseUrl = 'http://127.0.0.1:8000/api';

  /// Base URL untuk aset publik (mis. /storage/...) — baseUrl tanpa sufiks '/api'.
  static String get assetBaseUrl => baseUrl.endsWith('/api')
      ? baseUrl.substring(0, baseUrl.length - 4)
      : baseUrl;

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

  // --- CSO Endpoints ---

  Future<Response> getCsoProfile() async {
    return await _dio.get('/cso/profile');
  }

  Future<Response> updateCsoProfile(Map<String, dynamic> data) async {
    return await _dio.post('/cso/profile/update', data: data);
  }

  Future<Response> changeCsoPassword(
    String currentPassword,
    String newPassword,
  ) async {
    return await _dio.post(
      '/cso/profile/password',
      data: {
        'current_password': currentPassword,
        'new_password': newPassword,
        'new_password_confirmation': newPassword,
      },
    );
  }

  Future<Response> getCsoZones() async {
    return await _dio.get('/cso/zones');
  }

  Future<Response> getCsoAvailableDrivers() async {
    return await _dio.get('/cso/available-drivers');
  }

  Future<Response> getCsoCompanyQris() async {
    return await _dio.get('/cso/company-qris');
  }

  Future<Response> getCsoDriverLocations() async {
    return await _dio.get('/cso/driver-locations');
  }

  Future<Response> getCsoDashboardStats() async {
    return await _dio.get('/cso/dashboard-stats');
  }

  Future<Response> getCsoHistory({String? startDate, String? endDate}) async {
    final Map<String, dynamic> params = {};
    if (startDate != null && endDate != null) {
      params['start_date'] = startDate;
      params['end_date'] = endDate;
    }
    return await _dio.get(
      '/cso/history',
      queryParameters: params.isEmpty ? null : params,
    );
  }

  /// Membuat order CSO (booking + transaksi + notifikasi) dalam satu panggilan.
  /// [proofImagePath] hanya diisi untuk metode QRIS (foto bukti transfer).
  Future<Response> csoProcessOrder({
    required int driverId,
    required int zoneId,
    required String method,
    required String passengerPhone,
    String? proofImagePath,
  }) async {
    final Map<String, dynamic> fields = {
      'driver_id': driverId,
      'zone_id': zoneId,
      'method': method,
      'passenger_phone': passengerPhone,
    };
    if (proofImagePath != null) {
      fields['payment_proof'] = await MultipartFile.fromFile(proofImagePath);
    }
    return await _dio.post(
      '/cso/process-order',
      data: FormData.fromMap(fields),
    );
  }

  Future<Response> csoChangeDriver(int bookingId, int newDriverId) async {
    return await _dio.post(
      '/cso/bookings/$bookingId/change-driver',
      data: {'new_driver_id': newDriverId},
    );
  }
}
