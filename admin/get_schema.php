<?php
/**
 * Retired developer helper.
 *
 * Database schema is deployment-owned by the versioned files in /sql. This
 * script intentionally performs no schema mutation and is never available over
 * HTTP. Keep it as a compatibility marker for old bookmarks/deploy scripts.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

fwrite(STDERR, "get_schema.php is retired. Apply the versioned sql/*.sql migrations with an authorized database deployment account.\n");
exit(64);
