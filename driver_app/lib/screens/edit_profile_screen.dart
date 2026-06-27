import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:google_fonts/google_fonts.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import '../widgets/sky_header.dart';
import '../widgets/gradient_button.dart';
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
        if (mounted) Navigator.pop(context); // Go back
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
      backgroundColor: AppColors.background,
      body: Column(
        children: [
          SkyHeader(
            title: 'Edit Profil',
            subtitle: 'Perbarui data diri & kendaraan',
            onBack: () => Navigator.pop(context),
          ),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(16, 22, 16, 24),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _buildSectionTitle("Informasi Akun"),
                    _buildTextField("Nama Lengkap", _nameController,
                        icon: Icons.badge_rounded),
                    _buildTextField("Username", _usernameController,
                        icon: Icons.alternate_email_rounded),
                    _buildTextField(
                      "Email",
                      _emailController,
                      type: TextInputType.emailAddress,
                      icon: Icons.email_rounded,
                    ),
                    _buildTextField(
                      "Nomor HP",
                      _phoneController,
                      type: TextInputType.phone,
                      icon: Icons.phone_rounded,
                    ),
                    const SizedBox(height: 22),
                    _buildSectionTitle("Informasi Kendaraan"),
                    _buildTextField(
                      "Model Mobil (Contoh: Avanza Hitam)",
                      _carModelController,
                      icon: Icons.directions_car_rounded,
                    ),
                    _buildTextField("Plat Nomor", _plateNumberController,
                        icon: Icons.confirmation_number_rounded),
                    const SizedBox(height: 28),
                    GradientButton(
                      label: "SIMPAN PERUBAHAN",
                      icon: Icons.save_rounded,
                      loading: _isLoading,
                      onPressed: _saveProfile,
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSectionTitle(String title) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16, left: 4),
      child: Row(
        children: [
          Container(
            width: 4,
            height: 18,
            decoration: BoxDecoration(
              gradient: AppColors.buttonGradient,
              borderRadius: BorderRadius.circular(4),
            ),
          ),
          const SizedBox(width: 10),
          Text(
            title,
            style: GoogleFonts.outfit(
              color: AppColors.ink,
              fontSize: 17,
              fontWeight: FontWeight.bold,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTextField(
    String label,
    TextEditingController controller, {
    TextInputType? type,
    IconData? icon,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: GoogleFonts.outfit(
              color: AppColors.inkSoft,
              fontWeight: FontWeight.w600,
              fontSize: 13,
            ),
          ),
          const SizedBox(height: 8),
          TextFormField(
            controller: controller,
            keyboardType: type,
            style: GoogleFonts.outfit(color: AppColors.ink),
            validator: (val) =>
                val == null || val.isEmpty ? 'Wajib diisi' : null,
            decoration: InputDecoration(
              prefixIcon: icon == null
                  ? null
                  : Icon(icon, color: AppColors.cyan, size: 20),
            ),
          ),
        ],
      ),
    );
  }
}
