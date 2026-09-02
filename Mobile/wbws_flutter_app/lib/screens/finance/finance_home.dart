import 'package:flutter/material.dart';
import '../../widgets/use_website_note.dart';

class FinanceHomeScreen extends StatefulWidget {
  const FinanceHomeScreen({super.key});
  @override
  State<FinanceHomeScreen> createState() => FinanceHomeScreenState();
}

class FinanceHomeScreenState extends State<FinanceHomeScreen> {
  void refresh() {}

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
          ),
          const SliverPadding(
            padding: EdgeInsets.fromLTRB(16, 8, 16, 24),
            sliver: SliverToBoxAdapter(
              child: UseWebsiteNote(
                title: 'Finance stays on the website',
                body: 'Income, fees and reports are not on the phone. Sign in on the website to do this work.',
              ),
            ),
          ),
        ],
      ),
    );
  }
}
