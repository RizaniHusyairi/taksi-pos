import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../services/api_service.dart';
import 'package:dio/dio.dart';

class AuthProvider with ChangeNotifier {
  final ApiService _apiService = ApiService();
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  bool _isLoading = false;
  bool _isAuthenticated = false;
  Map<String, dynamic>? _user;

  bool get isLoading => _isLoading;
  bool get isAuthenticated => _isAuthenticated;
  Map<String, dynamic>? get user => _user;

  Future<void> checkLoginStatus() async {
    final token = await _storage.read(key: 'auth_token');
    if (token != null) {
      try {
        await fetchProfile();
        _isAuthenticated = true;
      } catch (e) {
        // Token might be invalid
        await logout();
      }
    }
    notifyListeners();
  }

  Future<bool> login(String username, String password) async {
    _isLoading = true;
    notifyListeners();

    try {
      final response = await _apiService.login(username, password);
      // Response structure: {message: "...", access_token: "...", user: {...}}
      final token = response.data['access_token'];
      final userData = response.data['user'];

      await _storage.write(key: 'auth_token', value: token);
      _user = userData;
      _isAuthenticated = true;

      // Initial Fetch Profile to get Status etc
      await fetchProfile();

      _isLoading = false;
      notifyListeners();
      return true;
    } on DioException catch (e) {
      _isLoading = false;
      notifyListeners();
      // You can handle specific errors here
      return false;
    } catch (e) {
      _isLoading = false;
      notifyListeners();
      return false;
    }
  }

  Future<void> fetchProfile() async {
    try {
      final response = await _apiService.getProfile();
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
    _isAuthenticated = false;
    _user = null;
    notifyListeners();
  }
}
