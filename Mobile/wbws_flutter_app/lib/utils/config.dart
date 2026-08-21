import 'package:flutter/material.dart';

/// FKSS App — Configuration Constants
class AppConfig {
  static const String apiBaseUrl = 'https://felegekidusan.arkeonethiopia.com/api/v1';
  static const String appName = 'FKSS';
  static const String appNameAmharic = 'ፈለገ ቅዱሳን ሰንበት ት/ቤት';
  static const String appVersion = '1.1.10';
  static const int appBuild = 12;
  static const String tokenKey = 'fkss_token';
  static const String refreshTokenKey = 'fkss_refresh_token';
  static const String userDataKey = 'fkss_user';
  static const int defaultPageSize = 20;
  static const int maxPageSize = 100;
  /// Slow Ethiopian 4G (≈18 KB/s) needs TLS + PHP room. 5s made the app
  /// lie about "no internet". Telegram keeps sockets open; Apple uses 60s.
  static const int connectionTimeout = 15;
  static const int receiveTimeout = 15;
  static const int postTimeout = 20;
}

/// User roles
class UserRoles {
  static const String superAdmin = 'super_admin';
  static const String schoolAdmin = 'school_admin';
  static const String infoDept = 'info_dept';
  static const String eduDept = 'edu_dept';
  static const String financeDept = 'finance_dept';
  static const String materialDept = 'material_dept';
  static const String teacher = 'teacher';
  static const String attendanceTaker = 'attendance_taker';

  static String displayName(String role) {
    switch (role) {
      case superAdmin: return 'Super Admin';
      case schoolAdmin: return 'School Admin';
      case infoDept: return 'Info Department';
      case eduDept: return 'Education Department';
      case financeDept: return 'Finance Department';
      case materialDept: return 'Material Department';
      case teacher: return 'Teacher';
      case attendanceTaker: return 'Attendance Taker';
      default: return role;
    }
  }

  static String displayNameAmharic(String role) {
    switch (role) {
      case superAdmin: return 'ዋና አስተዳዳሪ';
      case schoolAdmin: return 'የትምህርት ቤት አስተዳዳሪ';
      case infoDept: return 'የመረጃ ክፍል';
      case eduDept: return 'የትምህርት ክፍል';
      case financeDept: return 'የፋይናንስ ክፍል';
      case materialDept: return 'የቁሳቁስ ክፍል';
      case teacher: return 'መምህር';
      case attendanceTaker: return 'ቅጥረት ያዥ';
      default: return role;
    }
  }

  static bool canManageMembers(String role) =>
      [superAdmin, schoolAdmin, infoDept].contains(role);
  static bool canBrowseMembers(String role) =>
      [superAdmin, schoolAdmin, infoDept, eduDept].contains(role);
  static bool canTakeAttendance(String role) =>
      [superAdmin, schoolAdmin, teacher, attendanceTaker, eduDept].contains(role);
  static bool canManageGrades(String role) =>
      [superAdmin, schoolAdmin, teacher, eduDept].contains(role);
  static bool canManageEducation(String role) =>
      [superAdmin, schoolAdmin, eduDept].contains(role);
}

// ============================================================
// ROLE-BASED NAVIGATION
// ============================================================

class NavTab {
  final String id;
  final String label;
  final IconData icon;
  final IconData activeIcon;
  const NavTab({required this.id, required this.label, required this.icon, IconData? activeIcon})
      : activeIcon = activeIcon ?? icon;
}

List<NavTab> getTabsForRole(String role) {
  switch (role) {
    // ---- TEACHER ----
    case UserRoles.teacher:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'attendance', label: 'Attendance', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'grades', label: 'Grades', icon: Icons.grading_outlined, activeIcon: Icons.grading_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- ATTENDANCE TAKER ----
    case UserRoles.attendanceTaker:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'attendance', label: 'Attendance', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- EDUCATION DEPARTMENT ----
    case UserRoles.eduDept:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'attendance', label: 'Attend.', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'grades', label: 'Grades', icon: Icons.grading_outlined, activeIcon: Icons.grading_rounded),
        NavTab(id: 'members', label: 'Students', icon: Icons.people_outline, activeIcon: Icons.people_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- SUPER ADMIN ----
    case UserRoles.superAdmin:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'members', label: 'Members', icon: Icons.people_outline, activeIcon: Icons.people_rounded),
        NavTab(id: 'attendance', label: 'Attend.', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'grades', label: 'Grades', icon: Icons.grading_outlined, activeIcon: Icons.grading_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- SCHOOL ADMIN ----
    case UserRoles.schoolAdmin:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'members', label: 'Members', icon: Icons.people_outline, activeIcon: Icons.people_rounded),
        NavTab(id: 'attendance', label: 'Attend.', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'grades', label: 'Grades', icon: Icons.grading_outlined, activeIcon: Icons.grading_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- INFO DEPARTMENT ----
    case UserRoles.infoDept:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'members', label: 'Members', icon: Icons.people_outline, activeIcon: Icons.people_rounded),
        NavTab(id: 'attendance', label: 'Attend.', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- FINANCE DEPARTMENT ----
    case UserRoles.financeDept:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- MATERIAL DEPARTMENT ----
    case UserRoles.materialDept:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- FALLBACK ----
    default:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];
  }
}
