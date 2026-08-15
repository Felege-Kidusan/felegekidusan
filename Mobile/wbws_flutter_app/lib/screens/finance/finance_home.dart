import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../../widgets/stat_card.dart';
import '../../widgets/error_card.dart';
import '../../widgets/feature_tile.dart';

class FinanceHomeScreen extends StatefulWidget {
  const FinanceHomeScreen({super.key});
  @override
  State<FinanceHomeScreen> createState() => FinanceHomeScreenState();
}

class FinanceHomeScreenState extends State<FinanceHomeScreen> {
  final _api = ApiService();
  bool _loading = false;

  @override
  void initState() { super.initState(); }
  void refresh() => setState(() {});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: CustomScrollView(
        slivers: [
          SliverAppBar(floating: true, automaticallyImplyLeading: false,
            title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('FKSS', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
              Text('የፋይናንስ ክፍል • Finance Dept', style: TextStyle(fontSize: 11, color: Colors.white70)),
            ]),
            actions: [IconButton(icon: const Icon(Icons.refresh_rounded, size: 22), onPressed: refresh)],
          ),
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
            sliver: SliverList(delegate: SliverChildListDelegate(_buildContent())),
          ),
        ],
      ),
    );
  }

  List<Widget> _buildContent() {
    return [
      Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [AppTheme.primaryDark, AppTheme.primary, AppTheme.primaryLight],
            begin: Alignment.topLeft, end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('የፋይናንስ ክፍል', style: TextStyle(fontSize: 13, color: Colors.grey.shade300, fontFamily: 'NotoSansEthiopic')),
          const SizedBox(height: 2),
          Text('Finance Department', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: Colors.white)),
          const SizedBox(height: 4),
          Text('Income, expenses, fees & financial reports', style: TextStyle(fontSize: 12, color: Colors.grey.shade300)),
        ]),
      ),
      const SizedBox(height: 16),

      Row(children: [
        Expanded(child: StatCard(label: 'Income', value: '---', icon: Icons.trending_up_rounded, color: AppTheme.success)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'Expense', value: '---', icon: Icons.trending_down_rounded, color: AppTheme.danger)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'Balance', value: '---', icon: Icons.account_balance_wallet, color: AppTheme.info)),
      ]),
      const SizedBox(height: 20),

      const SectionHeader(title: 'Financial Management'),
      GridView.count(
        crossAxisCount: 3, shrinkWrap: true, physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 10, crossAxisSpacing: 10, childAspectRatio: 0.95,
        children: [
          FeatureTile(label: 'Add Income', icon: Icons.add_circle_rounded, color: AppTheme.success, onTap: () {}),
          FeatureTile(label: 'Add Expense', icon: Icons.remove_circle_rounded, color: AppTheme.danger, onTap: () {}),
          FeatureTile(label: 'Student Fees', icon: Icons.payments_rounded, color: AppTheme.primary, onTap: () {}),
          FeatureTile(label: 'Categories', icon: Icons.category_rounded, color: AppTheme.accent, onTap: () {}),
          FeatureTile(label: 'Reports', icon: Icons.bar_chart_rounded, color: AppTheme.warning, onTap: () {}),
          FeatureTile(label: 'Settings', icon: Icons.settings_rounded, color: Colors.grey, onTap: () {}),
        ],
      ),
    ];
  }
}




