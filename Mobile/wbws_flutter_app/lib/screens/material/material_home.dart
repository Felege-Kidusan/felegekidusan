import 'package:flutter/material.dart';
import '../../widgets/use_website_note.dart';

class MaterialHomeScreen extends StatefulWidget {
  const MaterialHomeScreen({super.key});
  @override
  State<MaterialHomeScreen> createState() => MaterialHomeScreenState();
}

class MaterialHomeScreenState extends State<MaterialHomeScreen> {
  void refresh() {}

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
          ),
          const SliverPadding(
            padding: EdgeInsets.fromLTRB(16, 8, 16, 24),
            sliver: SliverToBoxAdapter(
              child: UseWebsiteNote(
                title: 'Materials stay on the website',
                body: 'Inventory is not on the phone. Sign in on the website to receive or issue items.',
              ),
            ),
          ),
        ],
      ),
    );
  }
}
