import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../utils/theme.dart';

/// ════════════════════════════════════════════════════════════
/// Mobile member registration (Phase 9) — the registration desk
/// without a PC. Mirrors the website's core rules:
///   - one full-name input, split into First / Father / Grandfather;
///   - gender required; age group (ministry category) drives the
///     member code letter server-side;
///   - strong-duplicate detection server-side: a 409 comes back with
///     the matching member and the operator must explicitly override
///     WITH a reason (audited).
/// The server (POST /members) validates and stays authoritative.
/// ════════════════════════════════════════════════════════════
class RegisterMemberScreen extends StatefulWidget {
  const RegisterMemberScreen({super.key});

  @override
  State<RegisterMemberScreen> createState() => _RegisterMemberScreenState();
}

class _RegisterMemberScreenState extends State<RegisterMemberScreen> {
  final _api = ApiService();
  final _form = GlobalKey<FormState>();

  final _fullName = TextEditingController();
  String _gender = '';
  String _ageGroup = '';
  final _section = TextEditingController();
  final _phone = TextEditingController();

  bool _busy = false;
  Map<String, dynamic>? _duplicate;

  static const _ageGroups = [
    ['', 'No age group (code pending)'],
    ['7_13', 'A — 7-13'],
    ['14_17', 'B — 14-17'],
    ['18_plus', 'C — 18+'],
  ];

  @override
  void dispose() {
    _fullName.dispose();
    _section.dispose();
    _phone.dispose();
    super.dispose();
  }

  Map<String, dynamic> _payload({bool override = false, String reason = ''}) {
    final parts = _fullName.text.trim().split(RegExp(r'\s+'));
    return {
      'student_name': parts.isNotEmpty ? parts[0] : '',
      'father_name': parts.length > 1 ? parts[1] : '',
      'grandfather_name': parts.length > 2 ? parts.sublist(2).join(' ') : '',
      'gender': _gender,
      if (_ageGroup.isNotEmpty) 'age_group': _ageGroup,
      if (_section.text.trim().isNotEmpty)
        'current_section': _section.text.trim(),
      if (_phone.text.trim().isNotEmpty)
        'phone_number': _phone.text.trim(),
      'registration_type': 'direct',
      'status': 'active',
      if (override) ...{
        'duplicate_override': '1',
        'duplicate_override_reason': reason,
      },
    };
  }

  Future<void> _submit({bool override = false, String reason = ''}) async {
    setState(() => _busy = true);
    final res = await _api.createMember(
        _payload(override: override, reason: reason));
    if (!mounted) return;
    setState(() => _busy = false);

    if (res.success) {
      final code = '${res.data?['member_code'] ?? 'Pending'}';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('Member registered. Code: $code'),
          backgroundColor: AppTheme.success));
      Navigator.of(context).pop(true);
      return;
    }

    final dup = res.data?['duplicate'];
    if (res.statusCode == 409 && dup is Map) {
      setState(() => _duplicate = Map<String, dynamic>.from(dup));
      await _askOverride();
      return;
    }
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(res.message ?? 'Registration failed.'),
        backgroundColor: AppTheme.danger));
  }

  Future<void> _askOverride() async {
    final dup = _duplicate;
    if (dup == null) return;
    final ctrl = TextEditingController();
    final reason = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Possible duplicate'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
                'A strongly matching member already exists:\n${dup['name'] ?? ''} (${dup['member_code'] ?? ''})',
                style: const TextStyle(fontSize: 13)),
            const SizedBox(height: 12),
            TextField(
              controller: ctrl,
              maxLines: 2,
              maxLength: 500,
              decoration: const InputDecoration(
                  hintText: 'Reason for registering anyway…'),
            ),
          ],
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Cancel')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.warning),
            onPressed: () => Navigator.pop(ctx, ctrl.text.trim()),
            child: const Text('Register anyway'),
          ),
        ],
      ),
    );
    if (reason == null) return;
    if (reason.length < 3) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('A valid duplicate override reason is required.')));
      return;
    }
    await _submit(override: true, reason: reason);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Register Member')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            TextFormField(
              controller: _fullName,
              validator: (v) {
                final parts = (v ?? '').trim().split(RegExp(r'\s+'));
                if ((v ?? '').trim().isEmpty) return 'Full name is required.';
                if (parts.length < 2) {
                  return 'Give at least first and father name.';
                }
                return null;
              },
              decoration: const InputDecoration(
                labelText: 'Full name (First Father Grandfather)',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                Expanded(
                  child: DropdownButtonFormField<String>(
                    value: _gender.isEmpty ? null : _gender,
                    decoration: const InputDecoration(
                        labelText: 'Gender',
                        border: OutlineInputBorder()),
                    items: const [
                      DropdownMenuItem(value: 'male', child: Text('Male')),
                      DropdownMenuItem(value: 'female', child: Text('Female')),
                    ],
                    validator: (v) =>
                        (v == null || v.isEmpty) ? 'Required' : null,
                    onChanged: (v) => setState(() => _gender = v ?? ''),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),
            DropdownButtonFormField<String>(
              value: _ageGroup,
              decoration: const InputDecoration(
                  labelText: 'Ministry category (age group)',
                  border: OutlineInputBorder()),
              items: [
                for (final g in _ageGroups)
                  DropdownMenuItem(value: g[0], child: Text(g[1])),
              ],
              onChanged: (v) => setState(() => _ageGroup = v ?? ''),
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _section,
              decoration: const InputDecoration(
                labelText: 'Section (optional)',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(
                labelText: 'Phone (optional)',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 24),
            SizedBox(
              height: 54,
              child: ElevatedButton(
                style: ElevatedButton.styleFrom(
                    backgroundColor: AppTheme.primary,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14))),
                onPressed: _busy
                    ? null
                    : () {
                        if (_form.currentState!.validate()) _submit();
                      },
                child: _busy
                    ? const SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white))
                    : const Text('Register',
                        style: TextStyle(
                            fontSize: 16, fontWeight: FontWeight.w800)),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
