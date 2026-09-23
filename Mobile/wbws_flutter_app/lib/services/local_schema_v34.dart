/// Declarative SQLite v34 schema contract.
///
/// This file intentionally has no Flutter dependency. The runtime SQLite
/// migration harness reads these declarations as its schema source of truth.
const localDatabaseSchemaVersion = 34;

final class LocalColumnSpec {
  final String table;
  final String name;
  final String declaration;

  const LocalColumnSpec(this.table, this.name, this.declaration);
}

final class LegacyOutboxTableSpec {
  final String table;
  final List<String> businessKeyColumns;

  const LegacyOutboxTableSpec(this.table, this.businessKeyColumns);
}

const localSessionStateV34Sql = '''
  CREATE TABLE IF NOT EXISTS local_session_state (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    owner_user_id INTEGER,
    owner_username TEXT,
    owner_display_name TEXT,
    owner_role TEXT,
    owner_authorization_version INTEGER,
    state TEXT NOT NULL,
    reason TEXT,
    generation INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL
  )
''';

const legacyOutboxTableSpecs = <LegacyOutboxTableSpec>[
  LegacyOutboxTableSpec('pending_attendance', ['class_id', 'date']),
  LegacyOutboxTableSpec('pending_grades', ['assessment_id']),
  LegacyOutboxTableSpec('pending_mezmur', ['date', 'section']),
  LegacyOutboxTableSpec('pending_hr', ['date', 'section']),
];

const localV34ColumnSpecs = <LocalColumnSpec>[
  LocalColumnSpec('pending_attendance', 'sync_state', "TEXT NOT NULL DEFAULT 'pending'"),
  LocalColumnSpec('pending_attendance', 'attempt_count', "INTEGER NOT NULL DEFAULT 0"),
  LocalColumnSpec('pending_attendance', 'next_attempt_at', "TEXT"),
  LocalColumnSpec('pending_attendance', 'last_attempt_at', "TEXT"),
  LocalColumnSpec('pending_attendance', 'failure_code', "TEXT"),
  LocalColumnSpec('pending_attendance', 'failure_http_status', "INTEGER"),
  LocalColumnSpec('pending_attendance', 'failed_at', "TEXT"),
  LocalColumnSpec('pending_attendance', 'created_authorization_version', "INTEGER"),
  LocalColumnSpec('pending_attendance', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('pending_grades', 'sync_state', "TEXT NOT NULL DEFAULT 'pending'"),
  LocalColumnSpec('pending_grades', 'attempt_count', "INTEGER NOT NULL DEFAULT 0"),
  LocalColumnSpec('pending_grades', 'next_attempt_at', "TEXT"),
  LocalColumnSpec('pending_grades', 'last_attempt_at', "TEXT"),
  LocalColumnSpec('pending_grades', 'failure_code', "TEXT"),
  LocalColumnSpec('pending_grades', 'failure_http_status', "INTEGER"),
  LocalColumnSpec('pending_grades', 'failed_at', "TEXT"),
  LocalColumnSpec('pending_grades', 'created_authorization_version', "INTEGER"),
  LocalColumnSpec('pending_grades', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('pending_mezmur', 'sync_state', "TEXT NOT NULL DEFAULT 'pending'"),
  LocalColumnSpec('pending_mezmur', 'attempt_count', "INTEGER NOT NULL DEFAULT 0"),
  LocalColumnSpec('pending_mezmur', 'next_attempt_at', "TEXT"),
  LocalColumnSpec('pending_mezmur', 'last_attempt_at', "TEXT"),
  LocalColumnSpec('pending_mezmur', 'failure_code', "TEXT"),
  LocalColumnSpec('pending_mezmur', 'failure_http_status', "INTEGER"),
  LocalColumnSpec('pending_mezmur', 'failed_at', "TEXT"),
  LocalColumnSpec('pending_mezmur', 'created_authorization_version', "INTEGER"),
  LocalColumnSpec('pending_mezmur', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('pending_hr', 'sync_state', "TEXT NOT NULL DEFAULT 'pending'"),
  LocalColumnSpec('pending_hr', 'attempt_count', "INTEGER NOT NULL DEFAULT 0"),
  LocalColumnSpec('pending_hr', 'next_attempt_at', "TEXT"),
  LocalColumnSpec('pending_hr', 'last_attempt_at', "TEXT"),
  LocalColumnSpec('pending_hr', 'failure_code', "TEXT"),
  LocalColumnSpec('pending_hr', 'failure_http_status', "INTEGER"),
  LocalColumnSpec('pending_hr', 'failed_at', "TEXT"),
  LocalColumnSpec('pending_hr', 'created_authorization_version', "INTEGER"),
  LocalColumnSpec('pending_hr', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('pending_hymn_ops', 'sync_state', "TEXT NOT NULL DEFAULT 'pending'"),
  LocalColumnSpec('pending_hymn_ops', 'attempt_count', "INTEGER NOT NULL DEFAULT 0"),
  LocalColumnSpec('pending_hymn_ops', 'next_attempt_at', "TEXT"),
  LocalColumnSpec('pending_hymn_ops', 'last_attempt_at', "TEXT"),
  LocalColumnSpec('pending_hymn_ops', 'failure_code', "TEXT"),
  LocalColumnSpec('pending_hymn_ops', 'failure_http_status', "INTEGER"),
  LocalColumnSpec('pending_hymn_ops', 'failed_at', "TEXT"),
  LocalColumnSpec('pending_hymn_ops', 'created_authorization_version', "INTEGER"),
  LocalColumnSpec('pending_hymn_ops', 'created_by_user_id', 'INTEGER'),
  LocalColumnSpec('pending_hymn_ops', 'entity_key', 'TEXT'),
  LocalColumnSpec('pending_hymn_ops', 'depends_on', 'INTEGER'),
  LocalColumnSpec('comm_outbox', 'last_attempt_at', 'TEXT'),
  LocalColumnSpec('comm_outbox', 'failure_code', 'TEXT'),
  LocalColumnSpec('comm_outbox', 'failure_http_status', 'INTEGER'),
  LocalColumnSpec('comm_outbox', 'failed_at', 'TEXT'),
  LocalColumnSpec('comm_outbox', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('comm_outbox', 'created_authorization_version', 'INTEGER'),
  LocalColumnSpec('comm_drafts', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('comm_drafts', 'created_authorization_version', 'INTEGER'),
];

const localV34IndexSql = <String>[
  '''CREATE INDEX IF NOT EXISTS idx_pending_attendance_operation
     ON pending_attendance(client_op_id, sync_state, synced)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_attendance_owner_due
     ON pending_attendance(owner_user_id, sync_state, next_attempt_at)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_attendance_overlay
     ON pending_attendance(class_id, date, synced, sync_state)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_grades_operation
     ON pending_grades(client_op_id, sync_state, synced)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_grades_owner_due
     ON pending_grades(owner_user_id, sync_state, next_attempt_at)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_grades_overlay
     ON pending_grades(assessment_id, synced, sync_state)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_mezmur_operation
     ON pending_mezmur(client_op_id, sync_state, synced)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_mezmur_owner_due
     ON pending_mezmur(owner_user_id, sync_state, next_attempt_at)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_mezmur_overlay
     ON pending_mezmur(date, section, synced, sync_state)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hr_operation
     ON pending_hr(client_op_id, sync_state, synced)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hr_owner_due
     ON pending_hr(owner_user_id, sync_state, next_attempt_at)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hr_overlay
     ON pending_hr(date, section, synced, sync_state)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hymn_operation
     ON pending_hymn_ops(client_op_id, sync_state, synced)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hymn_due
     ON pending_hymn_ops(sync_state, next_attempt_at, id)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hymn_dependency
     ON pending_hymn_ops(depends_on, sync_state)''',
  '''CREATE INDEX IF NOT EXISTS idx_comm_outbox_owner_due
     ON comm_outbox(owner_user_id, state, next_attempt_at, created_at)''',
  '''CREATE INDEX IF NOT EXISTS idx_comm_outbox_thread_order
     ON comm_outbox(thread_id, state, next_attempt_at, created_at, client_tag)''',
  '''CREATE INDEX IF NOT EXISTS idx_comm_drafts_owner_updated
     ON comm_drafts(owner_user_id, updated_at)''',
];
