import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../services/notification_service.dart';
import '../services/api_service.dart';
import 'package:dio/dio.dart';

class AuthProvider with ChangeNotifier {
  final ApiService _apiService = ApiService();
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  bool _isLoading = false;
  bool _isAuthenticated = false;
  Map<String, dynamic>? _user;
  String? _role;

  bool get isLoading => _isLoading;
  bool get isAuthenticated => _isAuthenticated;
  Map<String, dynamic>? get user => _user;

  /// Peran pengguna ('driver' atau 'cso'). Dipakai untuk menentukan shell & endpoint.
  String? get role => _role ?? _user?['role'] as String?;
  bool get isCso => role == 'cso';
  bool get isDriver => role == 'driver';

  Future<void> checkLoginStatus() async {
    final token = await _storage.read(key: 'auth_token');
    if (token != null) {
      // Muat role yang tersimpan dulu agar fetchProfile memanggil endpoint yang benar
      // (penting saat app dibuka ulang: _user masih null tapi role sudah diketahui).
      _role = await _storage.read(key: 'user_role');
      try {
        await fetchProfile();
        _isAuthenticated = true;

        // Sync FCM Token
        _updateFcmToken();
      } catch (e) {
        // Token might be invalid
        await logout();
      }
    }
    notifyListeners();
  }

  Future<dynamic> login(String username, String password) async {
    _isLoading = true;
    notifyListeners();

    try {
      final response = await _apiService.login(username, password);
      // Response structure: {message: "...", access_token: "...", user: {...}}
      final token = response.data['access_token'];
      final userData = response.data['user'];

      await _storage.write(key: 'auth_token', value: token);
      _role = userData?['role'] as String?;
      await _storage.write(key: 'user_role', value: _role);
      _user = userData;
      _isAuthenticated = true;

      // Initial Fetch Profile to get Status etc
      await fetchProfile();

      // Sync FCM
      await _updateFcmToken();

      _isLoading = false;
      notifyListeners();
      return true;
    } on DioException catch (e) {
      _isLoading = false;
      notifyListeners();
      if (e.response != null) {
         return "Api Error: ${e.response?.statusCode} - ${e.response?.data}";
      } else {
         return "Network Error: ${e.type.toString()} | ${e.error.toString()}";
      }
    } catch (e) {
      _isLoading = false;
      notifyListeners();
      return "Sistem Error: $e";
    }
  }

  Future<void> fetchProfile() async {
    try {
      // Endpoint profil berbeda per peran; CSO tidak boleh menembak /driver/profile.
      final response = isCso
          ? await _apiService.getCsoProfile()
          : await _apiService.getProfile();
      _user = response.data;
      notifyListeners();
    } catch (e) {
      rethrow;
    }
  }

  Future<void> logout() async {
    try {
      await _apiService.logout();
    } catch (e) {
      // Ignore errors during logout
    }
    await _storage.delete(key: 'auth_token');
    await _storage.delete(key: 'user_role');
    _isAuthenticated = false;
    _user = null;
    _role = null;
    notifyListeners();
  }

  Future<void> _updateFcmToken() async {
    try {
      final token = await NotificationService().getFcmToken();
      if (token != null) {
        await _apiService.updateFcmToken(token);
        print("FCM Token synced with backend");
      }
    } catch (e) {
      print("Failed to sync FCM Token: $e");
    }
  }
}
