import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../services/auth_service.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _uController = TextEditingController();
  final _pController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _obscureText = true;

  String _debugLog = 'Ready...';

  void _submit() async {
    setState(() => _debugLog = 'Checking inputs...');

    if (_formKey.currentState!.validate()) {
      setState(() => _debugLog = 'Inputs OK. Connecting...');
      try {
        await Provider.of<AuthService>(
          context,
          listen: false,
        ).login(_uController.text, _pController.text);
        if (!mounted) return;
        setState(() => _debugLog = 'Login Success!');
      } catch (e) {
        setState(() => _debugLog = 'Error: $e');
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: Colors.red),
        );
      }
    } else {
      setState(() => _debugLog = 'Input Validation Failed!');
    }
  }

  @override
  Widget build(BuildContext context) {
    final isLoading = Provider.of<AuthService>(context).isLoading;

    return Scaffold(
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Form(
            key: _formKey,
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // ... (existing icon and text)
                const Icon(
                  Icons.local_taxi,
                  size: 80,
                  color: Color(0xFF2563eb),
                ),
                const SizedBox(height: 16),
                const Text(
                  'Taksi POS Driver',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 8),
                const Text(
                  'Masuk untuk mulai narik',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.grey),
                ),
                const SizedBox(height: 32),

                TextFormField(
                  controller: _uController,
                  decoration: const InputDecoration(
                    labelText: 'Username',
                    prefixIcon: Icon(Icons.person_outline),
                  ),
                  validator: (v) => v!.isEmpty ? 'Wajib diisi' : null,
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _pController,
                  obscureText: _obscureText,
                  decoration: InputDecoration(
                    labelText: 'Password',
                    prefixIcon: const Icon(Icons.lock_outline),
                    suffixIcon: IconButton(
                      icon: Icon(
                        _obscureText ? Icons.visibility : Icons.visibility_off,
                      ),
                      onPressed: () =>
                          setState(() => _obscureText = !_obscureText),
                    ),
                  ),
                  validator: (v) => v!.isEmpty ? 'Wajib diisi' : null,
                ),
                const SizedBox(height: 24),

                ElevatedButton(
                  onPressed: isLoading
                      ? null
                      : () {
                          setState(() => _debugLog = 'Button Clicked...');
                          _submit();
                        },
                  child: isLoading
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(
                            color: Colors.white,
                            strokeWidth: 2,
                          ),
                        )
                      : const Text(
                          'MASUK',
                          style: TextStyle(fontWeight: FontWeight.bold),
                        ),
                ),
                const SizedBox(height: 20),
                // DEBUG LOG ON SCREEN
                Container(
                  padding: const EdgeInsets.all(8),
                  color: Colors.black12,
                  width: double.infinity,
                  child: Text(
                    'Debug Log:\n$_debugLog',
                    style: const TextStyle(
                      fontSize: 10,
                      fontFamily: 'monospace',
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
