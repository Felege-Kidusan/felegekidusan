<?php

namespace App\Services;

use DirectoryIterator;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Streams consistent database backups to encrypted, non-public storage.
 *
 * The service owns storage, serialization, encryption, retention, and lookup.
 * HTTP/session concerns remain in the calling controllers.
 */
final class BackupService
{
    private const KEEP_COUNT = 7;
    private const INSERT_ROWS_PER_BATCH = 250;
    private const INSERT_BYTES_PER_BATCH = 1048576;
    private const FILE_PATTERN = '/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}_[0-9a-f]{8}\.sql\.gz\.ssb$/';
    private const LEGACY_FILE_PATTERN = '/^(?:backup|auto_backup)_[0-9A-Za-z_-]+\.sql$/';

    /**
     * @return array{name:string,size:int,rows:int,deleted:int,encrypted:bool}
     */
    public static function create(mysqli $conn, string $projectRoot): array
    {
        $secret = self::encryptionSecret();
        $storage = self::privateStorageRoot($projectRoot, true);
        $lockPath = $storage . DIRECTORY_SEPARATOR . '.backup.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Backup storage is unavailable.');
        }
        @chmod($lockPath, 0600);
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another backup is already running.');
        }

        $stamp = date('Y-m-d_H-i-s');
        $name = 'backup_' . $stamp . '_' . bin2hex(random_bytes(4)) . '.sql.gz.ssb';
        $finalPath = $storage . DIRECTORY_SEPARATOR . $name;
        $partPath = $storage . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(16)) . '.part';
        $writer = null;
        $inTransaction = false;
        $rowsTotal = 0;

        try {
            $writer = new EncryptedBackupWriter($partPath, $secret);
            $writer->write("-- SSMS database backup\n");
            $writer->write('-- Created: ' . date('Y-m-d H:i:s T') . "\n");
            $writer->write("SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

            $objects = self::databaseObjects($conn);
            if (!$conn->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ')) {
                throw new RuntimeException('Could not configure a consistent backup snapshot.');
            }
            if (!$conn->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')) {
                throw new RuntimeException('Could not start a consistent backup snapshot.');
            }
            $inTransaction = true;

            foreach ($objects as $object) {
                if ($object['type'] !== 'BASE TABLE') {
                    continue;
                }
                $table = $object['name'];
                $quoted = self::quoteIdentifier($table);
                $createResult = $conn->query("SHOW CREATE TABLE $quoted");
                if (!$createResult) {
                    throw new RuntimeException('Could not read database structure.');
                }
                $createRow = $createResult->fetch_assoc();
                $createResult->free();
                $createSql = (string)($createRow['Create Table'] ?? '');
                if ($createSql === '') {
                    throw new RuntimeException('Database structure is incomplete.');
                }

                $writer->write("\n-- Table: $quoted\nDROP TABLE IF EXISTS $quoted;\n$createSql;\n\n");
                $data = $conn->query("SELECT * FROM $quoted", MYSQLI_USE_RESULT);
                if (!$data) {
                    throw new RuntimeException('Could not read database data.');
                }

                $batch = [];
                $batchBytes = 0;
                while ($row = $data->fetch_row()) {
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = $value === null
                            ? 'NULL'
                            : "'" . $conn->real_escape_string((string)$value) . "'";
                    }
                    $line = '(' . implode(',', $values) . ')';
                    $batch[] = $line;
                    $batchBytes += strlen($line);
                    $rowsTotal++;
                    if (count($batch) >= self::INSERT_ROWS_PER_BATCH || $batchBytes >= self::INSERT_BYTES_PER_BATCH) {
                        self::writeInsertBatch($writer, $quoted, $batch);
                        $batch = [];
                        $batchBytes = 0;
                    }
                }
                $data->free();
                if ($batch) {
                    self::writeInsertBatch($writer, $quoted, $batch);
                }
            }

            // Views are emitted after base tables so their dependencies exist.
            foreach ($objects as $object) {
                if ($object['type'] !== 'VIEW') {
                    continue;
                }
                $view = $object['name'];
                $quoted = self::quoteIdentifier($view);
                $createResult = $conn->query("SHOW CREATE VIEW $quoted");
                if (!$createResult) {
                    throw new RuntimeException('Could not read database view structure.');
                }
                $createRow = $createResult->fetch_assoc();
                $createResult->free();
                $createSql = (string)($createRow['Create View'] ?? '');
                if ($createSql === '') {
                    throw new RuntimeException('Database view structure is incomplete.');
                }
                $writer->write("\n-- View: $quoted\nDROP VIEW IF EXISTS $quoted;\n$createSql;\n");
            }

            $writer->write("\nSET FOREIGN_KEY_CHECKS=1;\n");
            $conn->rollback(); // Ends the read-only snapshot without writing.
            $inTransaction = false;
            $writer->finish();
            $writer = null;

            if (!@rename($partPath, $finalPath)) {
                throw new RuntimeException('Could not activate the completed backup.');
            }
            @chmod($finalPath, 0600);
            $size = @filesize($finalPath);
            if ($size === false || $size <= 0) {
                @unlink($finalPath);
                throw new RuntimeException('The completed backup is empty.');
            }
            $deleted = self::enforceRetention($storage, self::KEEP_COUNT);

            return [
                'name' => $name,
                'size' => (int)$size,
                'rows' => $rowsTotal,
                'deleted' => $deleted,
                'encrypted' => true,
            ];
        } catch (Throwable $error) {
            if ($inTransaction) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }
            }
            if ($writer instanceof EncryptedBackupWriter) {
                $writer->abort();
            }
            @unlink($partPath);
            throw $error;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Return bounded metadata only; filesystem paths never reach presentation code.
     *
     * @return array<int,array{name:string,size:int,modified:int,encrypted:bool,legacy:bool}>
     */
    public static function listBackups(string $projectRoot, int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        $directories = [];
        try {
            $directories[] = [self::privateStorageRoot($projectRoot, false), false];
        } catch (Throwable $ignored) {
        }
        // Read-only compatibility for backups made by older versions.
        $directories[] = [rtrim($projectRoot, '/\\') . '/admin/uploads/backups', true];

        $files = [];
        foreach ($directories as [$directory, $legacyDirectory]) {
            if (!is_dir($directory)) {
                continue;
            }
            try {
                foreach (new DirectoryIterator($directory) as $item) {
                    if (!$item->isFile() || $item->isLink()) {
                        continue;
                    }
                    $name = $item->getFilename();
                    $encrypted = (bool)preg_match(self::FILE_PATTERN, $name);
                    $legacy = $legacyDirectory && (bool)preg_match(self::LEGACY_FILE_PATTERN, $name);
                    if (!$encrypted && !$legacy) {
                        continue;
                    }
                    $files[$name] = [
                        'name' => $name,
                        'size' => $item->getSize(),
                        'modified' => $item->getMTime(),
                        'encrypted' => $encrypted,
                        'legacy' => $legacy,
                    ];
                }
            } catch (Throwable $ignored) {
            }
        }

        usort($files, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);
        return array_slice($files, 0, $limit);
    }

    /** Resolve an allowlisted backup name to a contained regular file. */
    public static function resolveForDownload(string $name, string $projectRoot): ?string
    {
        if (!preg_match(self::FILE_PATTERN, $name) && !preg_match(self::LEGACY_FILE_PATTERN, $name)) {
            return null;
        }
        $roots = [];
        try {
            $roots[] = self::privateStorageRoot($projectRoot, false);
        } catch (Throwable $ignored) {
        }
        $roots[] = rtrim($projectRoot, '/\\') . '/admin/uploads/backups';

        foreach ($roots as $root) {
            $rootReal = realpath($root);
            if ($rootReal === false || !is_dir($rootReal)) {
                continue;
            }
            $candidate = realpath($rootReal . DIRECTORY_SEPARATOR . $name);
            if ($candidate !== false
                && strpos($candidate, $rootReal . DIRECTORY_SEPARATOR) === 0
                && is_file($candidate)
                && !is_link($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Decrypt and decompress one encrypted backup for an operator on the CLI.
     * The destination must be a new absolute path outside the web root.
     */
    public static function decryptToSql(string $name, string $outputPath, string $projectRoot): int
    {
        if (!preg_match(self::FILE_PATTERN, $name)) {
            throw new RuntimeException('Only encrypted SSMS backup files can be restored.');
        }
        $source = self::resolveForDownload($name, $projectRoot);
        if ($source === null) {
            throw new RuntimeException('Encrypted backup not found.');
        }
        $projectReal = realpath($projectRoot);
        $parentReal = realpath(dirname($outputPath));
        if ($outputPath === ''
            || $outputPath[0] !== DIRECTORY_SEPARATOR
            || $projectReal === false
            || $parentReal === false
            || $parentReal === $projectReal
            || strpos($parentReal, $projectReal . DIRECTORY_SEPARATOR) === 0
            || file_exists($outputPath)) {
            throw new RuntimeException('Restore output must be a new absolute file outside the web root.');
        }
        return EncryptedBackupReader::decrypt($source, $outputPath, self::encryptionSecret());
    }

    private static function databaseObjects(mysqli $conn): array
    {
        $result = $conn->query('SHOW FULL TABLES');
        if (!$result) {
            throw new RuntimeException('Could not list database objects.');
        }
        $objects = [];
        while ($row = $result->fetch_row()) {
            $objects[] = [
                'name' => (string)$row[0],
                'type' => strtoupper((string)($row[1] ?? 'BASE TABLE')),
            ];
        }
        $result->free();
        return $objects;
    }

    private static function writeInsertBatch(EncryptedBackupWriter $writer, string $quotedTable, array $batch): void
    {
        $writer->write("INSERT INTO $quotedTable VALUES\n" . implode(",\n", $batch) . ";\n");
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function encryptionSecret(): string
    {
        $secret = defined('BACKUP_ENCRYPTION_KEY')
            ? (string)constant('BACKUP_ENCRYPTION_KEY')
            : (defined('BACKUP_KEY') ? (string)constant('BACKUP_KEY') : '');
        if ($secret === '' || strpos($secret, 'REPLACE_WITH') === 0 || strlen($secret) < 32) {
            throw new RuntimeException('Backup encryption is not securely configured.');
        }
        if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')
            || !function_exists('deflate_init')) {
            throw new RuntimeException('Secure backup encryption is unavailable on this server.');
        }
        return $secret;
    }

    private static function privateStorageRoot(string $projectRoot, bool $create): string
    {
        $projectReal = realpath($projectRoot);
        if ($projectReal === false) {
            throw new RuntimeException('Application storage is unavailable.');
        }
        $configured = defined('BACKUP_STORAGE_PATH') ? trim((string)constant('BACKUP_STORAGE_PATH')) : '';
        $path = $configured !== ''
            ? rtrim($configured, '/\\')
            : dirname($projectReal) . DIRECTORY_SEPARATOR . 'ssms_secure_backups';
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR) {
            throw new RuntimeException('Backup storage must use an absolute path.');
        }
        if ($create && !is_dir($path) && !@mkdir($path, 0700, true)) {
            throw new RuntimeException('Backup storage could not be created.');
        }
        if (!is_dir($path)) {
            return $path;
        }
        @chmod($path, 0700);
        $real = realpath($path);
        if ($real === false
            || $real === $projectReal
            || strpos($real, $projectReal . DIRECTORY_SEPARATOR) === 0) {
            throw new RuntimeException('Backup storage must be outside the web root.');
        }
        if ($create && !is_writable($real)) {
            throw new RuntimeException('Backup storage is not writable.');
        }
        return $real;
    }

    private static function enforceRetention(string $storage, int $keep): int
    {
        $files = [];
        foreach (new DirectoryIterator($storage) as $item) {
            if ($item->isFile() && !$item->isLink() && preg_match(self::FILE_PATTERN, $item->getFilename())) {
                $files[] = ['path' => $item->getPathname(), 'modified' => $item->getMTime()];
            }
        }
        usort($files, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);
        $deleted = 0;
        foreach (array_slice($files, $keep) as $old) {
            if (@unlink($old['path'])) {
                $deleted++;
            }
        }
        return $deleted;
    }
}

/**
 * Small streaming adapter: SQL text -> gzip -> framed libsodium secretstream.
 * Memory remains bounded regardless of database size.
 */
final class EncryptedBackupWriter
{
    private const MAGIC = "SSMSBK01";
    private const CHUNK_BYTES = 65536;

    /** @var resource|null */
    private $handle;
    private $state;
    private $deflater;
    private string $compressedBuffer = '';
    private bool $finished = false;

    public function __construct(string $path, string $secret)
    {
        $this->handle = @fopen($path, 'xb');
        if ($this->handle === false) {
            throw new RuntimeException('Could not create the backup file.');
        }
        @chmod($path, 0600);
        $key = sodium_crypto_generichash(
            "SSMS backup encryption v1\0" . $secret,
            '',
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES
        );
        [$this->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        sodium_memzero($key);
        $this->deflater = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        if ($this->deflater === false) {
            $this->abort();
            throw new RuntimeException('Could not initialize backup compression.');
        }
        $this->writeAll(self::MAGIC . $header);
    }

    public function write(string $plaintext): void
    {
        if ($this->finished || $this->handle === null) {
            throw new RuntimeException('Backup writer is closed.');
        }
        $compressed = deflate_add($this->deflater, $plaintext, ZLIB_NO_FLUSH);
        if ($compressed === false) {
            throw new RuntimeException('Backup compression failed.');
        }
        $this->compressedBuffer .= $compressed;
        while (strlen($this->compressedBuffer) >= self::CHUNK_BYTES) {
            $chunk = substr($this->compressedBuffer, 0, self::CHUNK_BYTES);
            $this->compressedBuffer = (string)substr($this->compressedBuffer, self::CHUNK_BYTES);
            $this->emit($chunk, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
        }
    }

    public function finish(): void
    {
        if ($this->finished || $this->handle === null) {
            return;
        }
        $tail = deflate_add($this->deflater, '', ZLIB_FINISH);
        if ($tail === false) {
            throw new RuntimeException('Backup compression could not be finalized.');
        }
        $this->compressedBuffer .= $tail;
        while (strlen($this->compressedBuffer) > self::CHUNK_BYTES) {
            $chunk = substr($this->compressedBuffer, 0, self::CHUNK_BYTES);
            $this->compressedBuffer = (string)substr($this->compressedBuffer, self::CHUNK_BYTES);
            $this->emit($chunk, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
        }
        $this->emit($this->compressedBuffer, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        $this->compressedBuffer = '';
        fflush($this->handle);
        if (function_exists('fsync')) {
            @fsync($this->handle);
        }
        fclose($this->handle);
        $this->handle = null;
        $this->finished = true;
    }

    public function abort(): void
    {
        if ($this->handle !== null && is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        $this->finished = true;
        $this->compressedBuffer = '';
    }

    public function __destruct()
    {
        $this->abort();
    }

    private function emit(string $compressed, int $tag): void
    {
        $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
            $this->state,
            $compressed,
            '',
            $tag
        );
        $this->writeAll(pack('N', strlen($ciphertext)) . $ciphertext);
    }

    private function writeAll(string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($this->handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Backup storage write failed.');
            }
            $offset += $written;
        }
    }
}

/** Streaming inverse of EncryptedBackupWriter, exposed only through CLI code. */
final class EncryptedBackupReader
{
    private const MAGIC = "SSMSBK01";
    private const MAX_FRAME_BYTES = 65536 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    public static function decrypt(string $sourcePath, string $outputPath, string $secret): int
    {
        $input = @fopen($sourcePath, 'rb');
        $output = @fopen($outputPath, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
                @unlink($outputPath);
            }
            throw new RuntimeException('Could not open restore files.');
        }
        @chmod($outputPath, 0600);
        $writtenTotal = 0;

        try {
            $prefix = self::readExact(
                $input,
                strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
            );
            if (substr($prefix, 0, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Backup format is not recognized.');
            }
            $header = substr($prefix, strlen(self::MAGIC));
            $key = sodium_crypto_generichash(
                "SSMS backup encryption v1\0" . $secret,
                '',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES
            );
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            sodium_memzero($key);
            $inflater = inflate_init(ZLIB_ENCODING_GZIP);
            if ($state === false || $inflater === false) {
                throw new RuntimeException('Could not initialize backup restoration.');
            }

            $sawFinal = false;
            while (!$sawFinal) {
                $lengthBytes = self::readExact($input, 4);
                $lengthData = unpack('Nlength', $lengthBytes);
                $length = (int)($lengthData['length'] ?? 0);
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $length > self::MAX_FRAME_BYTES) {
                    throw new RuntimeException('Backup frame is invalid.');
                }
                $ciphertext = self::readExact($input, $length);
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
                if ($pulled === false) {
                    throw new RuntimeException('Backup authentication failed.');
                }
                [$compressed, $tag] = $pulled;
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                    && $tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    throw new RuntimeException('Backup stream tag is invalid.');
                }
                $sawFinal = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                $plaintext = inflate_add($inflater, $compressed, $sawFinal ? ZLIB_FINISH : ZLIB_NO_FLUSH);
                if ($plaintext === false) {
                    throw new RuntimeException('Backup decompression failed.');
                }
                self::writeAll($output, $plaintext);
                $writtenTotal += strlen($plaintext);
            }
            if (fread($input, 1) !== '') {
                throw new RuntimeException('Backup has unexpected trailing data.');
            }
            fflush($output);
            if (function_exists('fsync')) {
                @fsync($output);
            }
            fclose($input);
            fclose($output);
            return $writtenTotal;
        } catch (Throwable $error) {
            fclose($input);
            fclose($output);
            @unlink($outputPath);
            throw $error;
        }
    }

    /** @param resource $stream */
    private static function readExact($stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($stream, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Backup ended unexpectedly.');
            }
            $bytes .= $chunk;
        }
        return $bytes;
    }

    /** @param resource $stream */
    private static function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Restore output write failed.');
            }
            $offset += $written;
        }
    }
}
