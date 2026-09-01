-- 032: inverted word index for hymn search (Patch 25).
-- Why: MariaDB InnoDB FULLTEXT cannot tokenize Ge'ez/Amharic script
-- (verified live: 'ሰላም' -> 0 hits on a healthy index) and a
-- CREATE FULLTEXT INDEX build was observed returning 0 for EVERYTHING.
-- A word table is script-agnostic (PHP mb tokenizer, space-separated
-- Amharic words), prefix-searchable via clustered PK range scans, and
-- scales to hundreds of thousands of hymns.
CREATE TABLE IF NOT EXISTS mezmur_hymn_words (
  word VARBINARY(80) NOT NULL,
  hymn_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (word, hymn_id),
  KEY idx_mhw_hymn (hymn_id)
) ENGINE=InnoDB;
