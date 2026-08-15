import 'package:flutter/material.dart';
import 'theme.dart';

/// ============================================================
/// Ethiopian Calendar System for WBSS
/// ============================================================
/// Converts between Gregorian and Ethiopian calendars.
/// Ethiopian calendar is ~7-8 years behind Gregorian.
/// 13 months: 12 × 30 days + Pagumen (5 or 6 days)
/// New Year: September 11 (or 12 in Gregorian leap years)
/// ============================================================

class EthiopianDate {
  final int year;
  final int month;
  final int day;

  const EthiopianDate(this.year, this.month, this.day);

  @override
  String toString() => '$year-${month.toString().padLeft(2, '0')}-${day.toString().padLeft(2, '0')}';

  /// Format as "day/month/year" Ethiopian style
  String toDisplayString() => '$day ${monthNameAmharic(month)} $year';

  /// Short format "day monthName"
  String toShortString() => '$day ${monthNameAmharic(month)}';

  /// Format like the API needs: returns Gregorian yyyy-MM-dd
  String toGregorianString() {
    final gc = toGregorian();
    return '${gc.year}-${gc.month.toString().padLeft(2, '0')}-${gc.day.toString().padLeft(2, '0')}';
  }

  /// Convert this Ethiopian date to Gregorian DateTime
  DateTime toGregorian() => ethiopianToGregorian(year, month, day);

  /// Get today's Ethiopian date
  static EthiopianDate today() {
    final now = DateTime.now();
    return fromGregorian(now);
  }

  /// Convert Gregorian DateTime to Ethiopian
  static EthiopianDate fromGregorian(DateTime gc) {
    final result = gregorianToEthiopian(gc.year, gc.month, gc.day);
    return EthiopianDate(result[0], result[1], result[2]);
  }

  /// Parse from Gregorian string "yyyy-MM-dd"
  static EthiopianDate fromGregorianString(String gcDate) {
    final dt = DateTime.tryParse(gcDate);
    if (dt == null) return today();
    return fromGregorian(dt);
  }
}

// ============================================================
// MONTH NAMES
// ============================================================

const List<String> _monthNamesAmharic = [
  'መስከረም',   // 1
  'ጥቅምት',    // 2
  'ኅዳር',     // 3
  'ታኅሣሥ',    // 4
  'ጥር',      // 5
  'የካቲት',    // 6
  'መጋቢት',    // 7
  'ሚያዝያ',    // 8
  'ግንቦት',    // 9
  'ሰኔ',      // 10
  'ሐምሌ',     // 11
  'ነሐሴ',     // 12
  'ጳጉሜን',    // 13
];

const List<String> _monthNamesEnglish = [
  'Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit',
  'Megabit', 'Miazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagumen',
];

const List<String> _dayNamesAmharic = [
  'እሑድ', 'ሰኞ', 'ማክሰኞ', 'ረቡዕ', 'ሐሙስ', 'ዓርብ', 'ቅዳሜ',
];

const List<String> _dayNamesEnglishShort = [
  'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat',
];

String monthNameAmharic(int month) =>
    (month >= 1 && month <= 13) ? _monthNamesAmharic[month - 1] : '?';

String monthNameEnglish(int month) =>
    (month >= 1 && month <= 13) ? _monthNamesEnglish[month - 1] : '?';

String dayNameAmharic(DateTime gc) => _dayNamesAmharic[gc.weekday % 7];

int daysInEthiopianMonth(int month, int year) {
  if (month <= 12) return 30;
  // Month 13 (Pagumen): 6 days in leap year, 5 otherwise
  return _isEthiopianLeapYear(year) ? 6 : 5;
}

bool _isEthiopianLeapYear(int year) => (year % 4) == 3;

// ============================================================
// CONVERSION: Gregorian → Ethiopian
// ============================================================

/// Returns [year, month, day] in Ethiopian calendar
List<int> gregorianToEthiopian(int gyear, int gmonth, int gday) {
  // Use Julian Day Number as intermediate
  final jdn = _gregorianToJDN(gyear, gmonth, gday);
  return _jdnToEthiopian(jdn);
}

/// Returns DateTime in Gregorian
DateTime ethiopianToGregorian(int eyear, int emonth, int eday) {
  final jdn = _ethiopianToJDN(eyear, emonth, eday);
  return _jdnToGregorian(jdn);
}

// ── Julian Day Number helpers ──

int _gregorianToJDN(int year, int month, int day) {
  final a = ((14 - month) / 12).floor();
  final y = year + 4800 - a;
  final m = month + 12 * a - 3;
  return day + ((153 * m + 2) / 5).floor() + 365 * y +
      (y / 4).floor() - (y / 100).floor() + (y / 400).floor() - 32045;
}

DateTime _jdnToGregorian(int jdn) {
  final a = jdn + 32044;
  final b = ((4 * a + 3) / 146097).floor();
  final c = a - ((146097 * b) / 4).floor();
  final d = ((4 * c + 3) / 1461).floor();
  final e = c - ((1461 * d) / 4).floor();
  final m = ((5 * e + 2) / 153).floor();
  final day = e - ((153 * m + 2) / 5).floor() + 1;
  final month = m + 3 - 12 * (m / 10).floor();
  final year = 100 * b + d - 4800 + (m / 10).floor();
  return DateTime(year, month, day);
}

int _ethiopianToJDN(int year, int month, int day) {
  const epoch = 1724221;
  return epoch + 365 * (year - 1) + (year ~/ 4) +
      30 * (month - 1) + day - 1;
}

List<int> _jdnToEthiopian(int jdn) {
  const epoch = 1724221;
  final offset = jdn - epoch;
  final cycle = offset ~/ 1461; // 4-year cycle
  final remain = offset % 1461;

  // Within each 4-year cycle: 365, 365, 366, 365
  // The 3rd year (index 2) is leap (year % 4 == 3, Pagumen has 6 days)
  int yInCycle, dInYear;
  if (remain < 365) {
    yInCycle = 0; dInYear = remain;
  } else if (remain < 730) {
    yInCycle = 1; dInYear = remain - 365;
  } else if (remain < 1096) {
    yInCycle = 2; dInYear = remain - 730; // leap year: 366 days
  } else {
    yInCycle = 3; dInYear = remain - 1096;
  }

  final year = cycle * 4 + yInCycle + 1;
  final month = dInYear ~/ 30 + 1;
  final day = dInYear % 30 + 1;
  return [year, month, day];
}

// ============================================================
// ETHIOPIAN DATE PICKER DIALOG
// ============================================================

/// Shows an Ethiopian date picker and returns the selected date
/// as a Gregorian yyyy-MM-dd string (for API compatibility)
Future<String?> showEthiopianDatePicker({
  required BuildContext context,
  required String initialGregorianDate,
  DateTime? firstDate,
  DateTime? lastDate,
}) async {
  final initialEc = EthiopianDate.fromGregorianString(initialGregorianDate);
  final todayEc = EthiopianDate.today();

  // Default range: 1 year back to today
  final firstEc = firstDate != null
      ? EthiopianDate.fromGregorian(firstDate)
      : EthiopianDate(todayEc.year - 1, 1, 1);
  final lastEc = lastDate != null
      ? EthiopianDate.fromGregorian(lastDate)
      : todayEc;

  final result = await showDialog<EthiopianDate>(
    context: context,
    builder: (ctx) => _EthiopianDatePickerDialog(
      initial: initialEc,
      today: todayEc,
      firstYear: firstEc.year,
      lastYear: lastEc.year,
      lastDate: lastEc,
    ),
  );

  if (result == null) return null;
  return result.toGregorianString();
}

class _EthiopianDatePickerDialog extends StatefulWidget {
  final EthiopianDate initial;
  final EthiopianDate today;
  final int firstYear;
  final int lastYear;
  final EthiopianDate lastDate;

  const _EthiopianDatePickerDialog({
    required this.initial,
    required this.today,
    required this.firstYear,
    required this.lastYear,
    required this.lastDate,
  });

  @override
  State<_EthiopianDatePickerDialog> createState() => _EthiopianDatePickerDialogState();
}

class _EthiopianDatePickerDialogState extends State<_EthiopianDatePickerDialog> {
  late int _year;
  late int _month;
  late int _day;

  @override
  void initState() {
    super.initState();
    _year = widget.initial.year;
    _month = widget.initial.month;
    _day = widget.initial.day;
  }

  int get _maxDay => daysInEthiopianMonth(_month, _year);

  bool _isDateValid(int y, int m, int d) {
    // Can't be after lastDate
    if (y > widget.lastDate.year) return false;
    if (y == widget.lastDate.year && m > widget.lastDate.month) return false;
    if (y == widget.lastDate.year && m == widget.lastDate.month && d > widget.lastDate.day) return false;
    return true;
  }

  @override
  Widget build(BuildContext context) {
    if (_day > _maxDay) _day = _maxDay;

    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Header
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text('📅 ቀን ይምረጡ',
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700,
                        fontFamily: 'NotoSansEthiopic')),
                IconButton(
                  onPressed: () => Navigator.pop(context),
                  icon: const Icon(Icons.close, size: 20),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text('Select Date (Ethiopian Calendar)',
                style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
            const SizedBox(height: 16),

            // Year + Month selectors
            Row(
              children: [
                // Year
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('ዓመት / Year', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                      const SizedBox(height: 4),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        decoration: BoxDecoration(
                          border: Border.all(color: Colors.grey.shade700),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: DropdownButton<int>(
                          value: _year,
                          isExpanded: true,
                          underline: const SizedBox(),
                          items: List.generate(
                            widget.lastYear - widget.firstYear + 1,
                            (i) => widget.firstYear + i,
                          ).map((y) => DropdownMenuItem(
                            value: y,
                            child: Text('$y', style: const TextStyle(fontSize: 14)),
                          )).toList(),
                          onChanged: (v) {
                            if (v != null) setState(() => _year = v);
                          },
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                // Month
                Expanded(
                  flex: 2,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('ወር / Month', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                      const SizedBox(height: 4),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        decoration: BoxDecoration(
                          border: Border.all(color: Colors.grey.shade700),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: DropdownButton<int>(
                          value: _month,
                          isExpanded: true,
                          underline: const SizedBox(),
                          items: List.generate(13, (i) => i + 1)
                              .map((m) => DropdownMenuItem(
                            value: m,
                            child: Text('${_monthNamesAmharic[m - 1]}  (${_monthNamesEnglish[m - 1]})',
                                style: const TextStyle(fontSize: 13, fontFamily: 'NotoSansEthiopic')),
                          )).toList(),
                          onChanged: (v) {
                            if (v != null) setState(() => _month = v);
                          },
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),

            // Day grid
            Text('ቀን / Day', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: List.generate(_maxDay, (i) {
                final d = i + 1;
                final selected = d == _day;
                final isToday = _year == widget.today.year &&
                    _month == widget.today.month && d == widget.today.day;
                final enabled = _isDateValid(_year, _month, d);

                return InkWell(
                  onTap: enabled ? () => setState(() => _day = d) : null,
                  borderRadius: BorderRadius.circular(8),
                  child: Container(
                    width: 40,
                    height: 40,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: selected
                          ? AppTheme.primary
                          : isToday
                              ? AppTheme.primary.withOpacity(0.15)
                              : null,
                      borderRadius: BorderRadius.circular(8),
                      border: isToday && !selected
                          ? Border.all(color: AppTheme.primary, width: 1.5)
                          : null,
                    ),
                    child: Text(
                      '$d',
                      style: TextStyle(
                        fontSize: 14,
                        fontWeight: selected || isToday ? FontWeight.w700 : FontWeight.w400,
                        color: !enabled
                            ? Colors.grey.shade700
                            : selected
                                ? Colors.white
                                : null,
                      ),
                    ),
                  ),
                );
              }),
            ),
            const SizedBox(height: 16),

            // Selected date display
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              decoration: BoxDecoration(
                color: AppTheme.primary.withOpacity(0.1),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    '${_monthNamesAmharic[_month - 1]} $_day, $_year',
                    style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600,
                        fontFamily: 'NotoSansEthiopic'),
                  ),
                  const SizedBox(width: 8),
                  Text(
                    '(${_monthNamesEnglish[_month - 1]} $_day)',
                    style: TextStyle(fontSize: 12, color: AppTheme.textSecondary),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Action buttons
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                // Today button
                TextButton(
                  onPressed: () {
                    setState(() {
                      _year = widget.today.year;
                      _month = widget.today.month;
                      _day = widget.today.day;
                    });
                  },
                  child: const Text('ዛሬ / Today'),
                ),
                const SizedBox(width: 8),
                TextButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('Cancel'),
                ),
                const SizedBox(width: 8),
                ElevatedButton(
                  onPressed: () {
                    Navigator.pop(context, EthiopianDate(_year, _month, _day));
                  },
                  child: const Text('OK'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// ============================================================
// FORMATTING HELPERS
// ============================================================

/// Format a Gregorian date string to Ethiopian display
String formatGregorianAsEthiopian(String gcDate) {
  final ec = EthiopianDate.fromGregorianString(gcDate);
  return ec.toDisplayString();
}

/// Get Ethiopian greeting based on current hour
String getEthiopianGreeting() {
  final hour = DateTime.now().hour;
  if (hour < 12) return 'እንደምን አደሩ';
  if (hour < 17) return 'እንደምን ዋሉ';
  return 'እንደምን አመሹ';
}

/// Get today as Ethiopian formatted string
String getTodayEthiopian() {
  final ec = EthiopianDate.today();
  final gc = DateTime.now();
  final dayName = dayNameAmharic(gc);
  return '$dayName ${ec.toDisplayString()}';
}

