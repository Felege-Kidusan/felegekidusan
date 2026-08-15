import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';
import '../utils/theme.dart';

/// Shimmer skeleton loader — replaces CircularProgressIndicator
/// with content-shaped placeholders that pulse, so users see
/// "content is loading" instead of a spinning wheel.

class ShimmerBox extends StatelessWidget {
  final double width;
  final double height;
  final double radius;

  const ShimmerBox({
    super.key,
    required this.width,
    required this.height,
    this.radius = 10,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: AppTheme.surfaceLight,
        borderRadius: BorderRadius.circular(radius),
      ),
    );
  }
}

/// Dashboard skeleton — matches the teacher home layout
class DashboardSkeleton extends StatelessWidget {
  const DashboardSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor: AppTheme.surfaceLight,
      highlightColor: AppTheme.borderLight.withOpacity(0.8),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Welcome banner skeleton
            Container(
              height: 160,
              decoration: BoxDecoration(
                color: AppTheme.surfaceLight,
                borderRadius: BorderRadius.circular(16),
              ),
            ),
            const SizedBox(height: 16),

            // 3 stat cards
            Row(
              children: [
                Expanded(child: _cardSkeleton(90)),
                const SizedBox(width: 10),
                Expanded(child: _cardSkeleton(90)),
                const SizedBox(width: 10),
                Expanded(child: _cardSkeleton(90)),
              ],
            ),
            const SizedBox(height: 16),

            // Section title
            const ShimmerBox(width: 120, height: 16),
            const SizedBox(height: 12),

            // Class cards
            _cardSkeleton(72),
            const SizedBox(height: 8),
            _cardSkeleton(72),
            const SizedBox(height: 16),

            // Attendance card
            _cardSkeleton(120),
          ],
        ),
      ),
    );
  }

  Widget _cardSkeleton(double height) {
    return Container(
      height: height,
      decoration: BoxDecoration(
        color: AppTheme.surfaceLight,
        borderRadius: BorderRadius.circular(16),
      ),
    );
  }
}

/// Student list skeleton — matches attendance screen rows
class StudentListSkeleton extends StatelessWidget {
  final int count;
  const StudentListSkeleton({super.key, this.count = 6});

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor: AppTheme.surfaceLight,
      highlightColor: AppTheme.borderLight.withOpacity(0.8),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Column(
          children: List.generate(count, (i) => Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Container(
              height: 60,
              decoration: BoxDecoration(
                color: AppTheme.surfaceLight,
                borderRadius: BorderRadius.circular(16),
              ),
            ),
          )),
        ),
      ),
    );
  }
}

/// Member list skeleton
class MemberListSkeleton extends StatelessWidget {
  final int count;
  const MemberListSkeleton({super.key, this.count = 8});

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor: AppTheme.surfaceLight,
      highlightColor: AppTheme.borderLight.withOpacity(0.8),
      child: ListView.builder(
        physics: const NeverScrollableScrollPhysics(),
        itemCount: count,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        itemBuilder: (_, i) => Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Container(
            height: 72,
            decoration: BoxDecoration(
              color: AppTheme.surfaceLight,
              borderRadius: BorderRadius.circular(16),
            ),
          ),
        ),
      ),
    );
  }
}

/// Member detail skeleton — avatar + info sections
class MemberDetailSkeleton extends StatelessWidget {
  const MemberDetailSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor: AppTheme.surfaceLight,
      highlightColor: AppTheme.borderLight.withOpacity(0.8),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            Container(height: 180, decoration: BoxDecoration(
              color: AppTheme.surfaceLight, borderRadius: BorderRadius.circular(16))),
            const SizedBox(height: 16),
            Container(height: 200, decoration: BoxDecoration(
              color: AppTheme.surfaceLight, borderRadius: BorderRadius.circular(16))),
            const SizedBox(height: 12),
            Container(height: 120, decoration: BoxDecoration(
              color: AppTheme.surfaceLight, borderRadius: BorderRadius.circular(16))),
            const SizedBox(height: 12),
            Container(height: 160, decoration: BoxDecoration(
              color: AppTheme.surfaceLight, borderRadius: BorderRadius.circular(16))),
          ],
        ),
      ),
    );
  }
}

