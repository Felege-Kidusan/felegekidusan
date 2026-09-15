import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/comm_store.dart';

/// O1 (offline-first) — pins for the pure thread row mappers. The
/// roundtrip must be the identity for every field the thread tile
/// renders; junk types must never throw (bad JSON tolerance is a
/// standing rule in this codebase).
void main() {
  test('roundtrip preserves every rendered field', () {
    final t = {
      'id': 42,
      'subject': 'Grade 4 parents',
      'participants_label': 'Alemitu Bekele, Chaltu Dame',
      'last_body': 'Meeting moved to 3pm',
      'last_message_at': '2026-09-15 09:41:00',
      'unread_count': 3,
      'message_count': 27,
      'created_at': '2026-08-01 10:00:00',
    };
    final back = threadFromRow(threadToRow(t));
    expect(back, t);
  });

  test('missing fields become safe defaults, never throws', () {
    final back = threadFromRow(threadToRow({'id': 7}));
    expect(back['id'], 7);
    expect(back['subject'], '');
    expect(back['participants_label'], isNull);
    expect(back['last_body'], isNull);
    expect(back['unread_count'], 0);
    expect(back['message_count'], 0);
  });

  test('lenient on numeric variance (double ids / string counts)', () {
    final back = threadFromRow(threadToRow({
      'id': 9.0,
      'unread_count': '2',
      'message_count': 11.0,
    }));
    expect(back['id'], 9);
    expect(back['unread_count'], 2); // string '2' leniently parsed
    expect(back['message_count'], 11);
  });

  test('null id maps to 0 (server always sends one; defensive only)', () {
    expect(threadToRow({})['id'], 0);
  });
}
