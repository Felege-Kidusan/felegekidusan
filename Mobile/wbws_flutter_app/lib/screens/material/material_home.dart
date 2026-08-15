import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../../widgets/stat_card.dart';
import '../../widgets/feature_tile.dart';

class MaterialHomeScreen extends StatefulWidget {
  const MaterialHomeScreen({super.key});
  @override
  State<MaterialHomeScreen> createState() => MaterialHomeScreenState();
}

class MaterialHomeScreenState extends State<MaterialHomeScreen> {
  final _api = ApiService();
  void refresh() => setState(() {});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: CustomScrollView(
        slivers: [
          SliverAppBar(floating: true, automaticallyImplyLeading: false,
            title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('FKSS', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
              Text('የቁሳቁስ ክፍል • Material Dept', style: TextStyle(fontSize: 11, color: Colors.white70)),
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
          Text('የቁሳቁስ ክፍል', style: TextStyle(fontSize: 13, color: Colors.grey.shade300, fontFamily: 'NotoSansEthiopic')),
          const SizedBox(height: 2),
          Text('Material Department', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: Colors.white)),
          const SizedBox(height: 4),
          Text('Inventory, supplies & resource management', style: TextStyle(fontSize: 12, color: Colors.grey.shade300)),
        ]),
      ),
      const SizedBox(height: 16),

      Row(children: [
        Expanded(child: StatCard(label: 'Total Items', value: '---', icon: Icons.inventory_2_rounded, color: AppTheme.primary)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'In Stock', value: '---', icon: Icons.check_box_rounded, color: AppTheme.success)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'Low Stock', value: '---', icon: Icons.warning_rounded, color: AppTheme.danger)),
      ]),
      const SizedBox(height: 20),

      const SectionHeader(title: 'Inventory Management'),
      GridView.count(
        crossAxisCount: 3, shrinkWrap: true, physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 10, crossAxisSpacing: 10, childAspectRatio: 0.95,
        children: [
          FeatureTile(label: 'All Items', icon: Icons.inventory_rounded, color: AppTheme.primary, onTap: () {}),
          FeatureTile(label: 'Receive', icon: Icons.move_to_inbox_rounded, color: AppTheme.success, onTap: () {}),
          FeatureTile(label: 'Issue', icon: Icons.outbox_rounded, color: AppTheme.warning, onTap: () {}),
          FeatureTile(label: 'Requests', icon: Icons.assignment_rounded, color: AppTheme.info, onTap: () {}),
          FeatureTile(label: 'Categories', icon: Icons.category_rounded, color: AppTheme.accent, onTap: () {}),
          FeatureTile(label: 'Settings', icon: Icons.settings_rounded, color: Colors.grey, onTap: () {}),
        ],
      ),
    ];
  }
}




