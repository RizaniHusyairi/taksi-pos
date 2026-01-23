import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class ApiService {
  // Use 10.0.2.2 for Android Emulator to access localhost
  // Use your machine's IP (e.g., 10.49.92.29) if testing on physical device
  static const String baseUrl = 'http://192.168.1.25:8080/api';

  late Dio _dio;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  ApiService() {
    _dio = Dio(
      BaseOptions(
        baseUrl: baseUrl,
        connectTimeout: const Duration(seconds: 10),
        receiveTimeout: const Duration(seconds: 10),
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      ),
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
}
