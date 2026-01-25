import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:google_fonts/google_fonts.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import 'package:dio/dio.dart';

class EditProfileScreen extends StatefulWidget {
  const EditProfileScreen({super.key});

  @override
  State<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends State<EditProfileScreen> {
  final _formKey = GlobalKey<FormState>();

  late TextEditingController _nameController;
  late TextEditingController _emailController;
  late TextEditingController _phoneController;
  late TextEditingController _usernameController;
  late TextEditingController _carModelController;
  late TextEditingController _plateNumberController;

  final ApiService _apiService = ApiService();
  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
    final auth = Provider.of<AuthProvider>(context, listen: false);
    final user = auth.user!;
    final profile = user['driver_profile'];

    _nameController = TextEditingController(text: user['name']);
    _emailController = TextEditingController(text: user['email']);
    _phoneController = TextEditingController(text: user['phone_number']);
    _usernameController = TextEditingController(text: user['username']);
    _carModelController = TextEditingController(
      text: profile?['car_model'] ?? '',
    );
    _plateNumberController = TextEditingController(
      text: profile?['plate_number'] ?? '',
    );
  }

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _phoneController.dispose();
    _usernameController.dispose();
    _carModelController.dispose();
    _plateNumberController.dispose();
    super.dispose();
  }

  Future<void> _saveProfile() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);

    try {
      final data = {
        'name': _nameController.text,
        'email': _emailController.text,
        'phone_number': _phoneController.text,
        'username': _usernameController.text,
        'car_model': _carModelController.text,
        'plate_number': _plateNumberController.text,
      };

      await _apiService.updateProfile(data);

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Profil berhasil diperbarui')),
        );
        // Refresh Auth Provider
        await Provider.of<AuthProvider>(context, listen: false).fetchProfile();
        Navigator.pop(context); // Go back
      }
    } on DioException catch (e) {
      if (mounted) {
        String msg = 'Gagal memperbarui profil';
        if (e.response?.statusCode == 422) {
          msg = e.response?.data['message'] ?? 'Data tidak valid';
        }
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(msg)));
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF1A1A1A),
      appBar: AppBar(
        title: Text(
          'EDIT PROFIL',
          style: GoogleFonts.outfit(
            fontWeight: FontWeight.bold,
            color: Colors.white,
          ),
        ),
        backgroundColor: Colors.transparent,
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _buildSectionTitle("Informasi Akun"),
              _buildTextField("Nama Lengkap", _nameController),
              _buildTextField("Username", _usernameController),
              _buildTextField(
                "Email",
                _emailController,
                TextInputType.emailAddress,
              ),
              _buildTextField(
                "Nomor HP",
                _phoneController,
                TextInputType.phone,
              ),

              const SizedBox(height: 24),
              _buildSectionTitle("Informasi Kendaraan"),
              _buildTextField(
                "Model Mobil (Contoh: Avanza Hitam)",
                _carModelController,
              ),
              _buildTextField("Plat Nomor", _plateNumberController),

              const SizedBox(height: 32),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFFD4AF37),
                    foregroundColor: Colors.black,
                    padding: const EdgeInsets.symmetric(vertical: 16),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                  ),
                  onPressed: _isLoading ? null : _saveProfile,
                  child: _isLoading
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.black,
                          ),
                        )
                      : Text(
                          "SIMPAN PERUBAHAN",
                          style: GoogleFonts.outfit(
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildSectionTitle(String title) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Text(
        title,
        style: GoogleFonts.outfit(
          color: const Color(0xFFD4AF37),
          fontSize: 18,
          fontWeight: FontWeight.bold,
        ),
      ),
    );
  }

  Widget _buildTextField(
    String label,
    TextEditingController controller, [
    TextInputType? type,
  ]) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: GoogleFonts.outfit(color: Colors.white70)),
          const SizedBox(height: 8),
          TextFormField(
            controller: controller,
            keyboardType: type,
            style: GoogleFonts.outfit(color: Colors.white),
            validator: (val) =>
                val == null || val.isEmpty ? 'Wajib diisi' : null,
            decoration: InputDecoration(
              filled: true,
              fillColor: Colors.white10,
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide: const BorderSide(color: Colors.white10),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide: const BorderSide(color: Color(0xFFD4AF37)),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
