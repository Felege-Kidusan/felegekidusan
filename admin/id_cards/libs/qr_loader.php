<?php
/**
 * QR library loader for the ID-card subsystem.
 *
 * The upstream phpqrcode distribution ships either as a multi-file set
 * (qrlib.php + parts) or as the concatenated single-file build
 * (phpqrcode.php). Some deployments lost the multi-file entry point,
 * which silently killed QR generation and made "renew" return 503.
 *
 * This loader defines QRcode exactly once, preferring qrlib.php when
 * present and falling back to the bundled single-file build otherwise.
 * Including it is always safe; when neither file exists, QRcode stays
 * undefined and callers keep their guarded behaviour.
 */

if (!class_exists('QRcode', false)) {
    $__qrCandidates = [
        __DIR__ . '/phpqrcode/qrlib.php',
        __DIR__ . '/phpqrcode/phpqrcode.php',
    ];
    foreach ($__qrCandidates as $__qrFile) {
        if (is_file($__qrFile)) {
            require_once $__qrFile;
            break;
        }
    }
    unset($__qrCandidates, $__qrFile);
}
