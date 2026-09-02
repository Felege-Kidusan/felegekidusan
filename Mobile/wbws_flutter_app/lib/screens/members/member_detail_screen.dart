import '../../widgets/loading_skeleton.dart';
import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/theme.dart';

class MemberDetailScreen extends StatefulWidget {
  final int memberId;
  const MemberDetailScreen({super.key, required this.memberId});
  
  @override
  State<MemberDetailScreen> createState() => _MemberDetailScreenState();
}

class _MemberDetailScreenState extends State<MemberDetailScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  Map<String, dynamic>? _member;
  bool _loading = true;
  bool _isOffline = false;
  String? _error;
  
  @override
  void initState() {
    super.initState();
    _loadMember();
  }
  
  Future<void> _loadMember() async {
    setState(() { _loading = true; _error = null; _isOffline = false; });
    final res = await _api.getMember(widget.memberId);
    if (!mounted) return;
    
    if (res.success && res.data != null) {
      setState(() { _member = res.data; _loading = false; });
    } else {
      // Fallback to cached member data
      final cached = await _db.getCachedMembers();
      final match = cached.where((m) => m['id'] == widget.memberId).toList();
      if (match.isNotEmpty) {
        setState(() { _member = match.first; _loading = false; _isOffline = !ConnectivityService().hasLink; });
      } else {
        setState(() { _error = res.message ?? 'Member not found'; _loading = false; _isOffline = res.isNetworkError; });
      }
    }
  }
  
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_member?['student_name'] ?? 'Member Details'),
      ),
      body: _loading
          ? const MemberDetailSkeleton()
          : _error != null
              ? Center(child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(_error!, style: const TextStyle(color: AppTheme.danger)),
                    TextButton(onPressed: _loadMember, child: const Text('Retry')),
                  ],
                ))
              : RefreshIndicator(
                  onRefresh: _loadMember,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      if (_isOffline)
                        Container(
                          margin: const EdgeInsets.only(bottom: 12),
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                          decoration: BoxDecoration(
                            color: AppTheme.warning.withOpacity(0.12),
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: AppTheme.warning.withOpacity(0.25)),
                          ),
                          child: Row(children: [
                            Icon(Icons.cloud_off, size: 16, color: AppTheme.warning),
                            const SizedBox(width: 8),
                            Expanded(child: Text('Showing cached data',
                                style: TextStyle(fontSize: 11, color: AppTheme.warning, fontWeight: FontWeight.w500))),
                          ]),
                        ),
                      _buildHeader(),
                      const SizedBox(height: 16),
                      _buildSection('Personal Information', [
                        _field('Full Name (Amharic)', _member?['full_name_am']),
                        _field('Student Name', _member?['student_name']),
                        _field('Father Name', _member?['father_name']),
                        _field('Grandfather', _member?['grandfather_name']),
                        _field('Gender', _member?['gender']),
                        _field('Date of Birth', _formatEcDate(_member?['date_of_birth'])),
                        _field('Age Group', _member?['age_group']),
                        _field('Member Code', _member?['member_code']),
                        _field('Member Type', _member?['member_type']),
                        _field('Registration', _member?['registration_type']),
                      ]),
                      const SizedBox(height: 12),
                      _buildSection('Contact', [
                        _field('Phone', _member?['phone_number']),
                        _field('Alt Phone', _member?['alt_phone_number']),
                        _field('Guardian', _member?['guardian_name']),
                        _field('Guardian Phone', _member?['guardian_phone1']),
                      ]),
                      const SizedBox(height: 12),
                      _buildSection('Education', [
                        _field('Section', _member?['current_section']),
                        _field('Education Level', _member?['education_level']),
                        if (_member?['current_class'] != null) ...[
                          _field('Class', _member?['current_class']?['class_name']),
                          _field('Enrolled At', _formatEcDate(_member?['current_class']?['enrolled_at'])),
                        ],
                      ]),
                      const SizedBox(height: 12),
                      _buildSection('Address', [
                        _field('City', _member?['city']),
                        _field('Sub City', _member?['sub_city']),
                        _field('Woreda', _member?['woreda']),
                        _field('Address', _member?['address']),
                      ]),
                      const SizedBox(height: 20),
                    ],
                  ),
                ),
    );
  }
  
  Widget _buildHeader() {
    final status = _member?['status'] ?? 'active';
    final statusColor = status == 'active' ? AppTheme.success
        : status == 'warning' ? AppTheme.warning : AppTheme.danger;
    
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            CircleAvatar(
              radius: 36,
              backgroundColor: AppTheme.primary.withOpacity(0.15),
              child: Text(
                (_member?['student_name'] ?? '?')[0].toUpperCase(),
                style: const TextStyle(color: AppTheme.primary, fontWeight: FontWeight.w700, fontSize: 28),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              '${_member?['student_name'] ?? ''} ${_member?['father_name'] ?? ''}',
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              textAlign: TextAlign.center,
            ),
            if (_member?['full_name_am'] != null) ...[
              const SizedBox(height: 4),
              Text(_member!['full_name_am'], style: TextStyle(fontSize: 14, color: AppTheme.textSecondary)),
            ],
            const SizedBox(height: 8),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusColor.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(status, style: TextStyle(color: statusColor, fontWeight: FontWeight.w600, fontSize: 12)),
                ),
                if (_member?['member_code'] != null) ...[
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: AppTheme.info.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Text(_member!['member_code'], style: const TextStyle(color: AppTheme.info, fontWeight: FontWeight.w600, fontSize: 12)),
                  ),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }
  
  Widget _buildSection(String title, List<Widget> children) {
    // Filter out empty fields
    final nonEmpty = children.where((w) => w is! SizedBox).toList();
    if (nonEmpty.isEmpty) return const SizedBox.shrink();
    
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
            const SizedBox(height: 10),
            ...children,
          ],
        ),
      ),
    );
  }
  
  /// Convert Gregorian date string to Ethiopian display
  String? _formatEcDate(dynamic value) {
    if (value == null || value.toString().trim().isEmpty) return null;
    try {
      return formatGregorianAsEthiopian(value.toString().substring(0, 10));
    } catch (_) {
      return value.toString();
    }
  }

  Widget _field(String label, dynamic value) {
    if (value == null || value.toString().trim().isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(label, style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
          ),
          Expanded(
            child: Text(value.toString(), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500)),
          ),
        ],
      ),
    );
  }
}

