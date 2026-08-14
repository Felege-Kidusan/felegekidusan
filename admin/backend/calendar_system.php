<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * School Management Calendar System — PHP Helper
 * ═══════════════════════════════════════════════════════════════
 * 
 * Include this file to get:
 * 1. $calendarMode — 'ethiopian' or 'gregorian'
 * 2. wbws_format_date() — PHP date formatter respecting system setting
 * 3. wbws_calendar_scripts() — Returns <script> tags to include JS lib
 * 4. ethiopian_date_format functions (enhanced)
 * 
 * Usage in any dashboard:
 *   require_once __DIR__ . '/../backend/calendar_system.php';
 *   echo wbws_calendar_scripts();  // in <head>
 * ═══════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/ethiopian_date.php';

/**
 * Get the system calendar mode from database
 * Caches in session to avoid repeated DB queries
 */
function wbws_get_calendar_mode($conn = null) {
    // Check session cache first
    if (!empty($_SESSION['wbws_calendar_mode'])) {
        return $_SESSION['wbws_calendar_mode'];
    }
    
    $mode = 'ethiopian'; // Default to Ethiopian
    
    if ($conn) {
        try {
            // Ensure the setting exists in dept_settings
            $conn->query("CREATE TABLE IF NOT EXISTS `dept_settings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `setting_key` VARCHAR(100) NOT NULL,
                `setting_value` TEXT DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `setting_key` (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            $r = $conn->query("SELECT setting_value FROM dept_settings WHERE setting_key = 'calendar_mode' LIMIT 1");
            if ($r && $row = $r->fetch_assoc()) {
                $mode = $row['setting_value'] ?: 'ethiopian';
            } else {
                // Insert default
                $conn->query("INSERT IGNORE INTO dept_settings (setting_key, setting_value) VALUES ('calendar_mode', 'ethiopian')");
            }
        } catch (Exception $e) {
            // Table might not exist yet
        }
    }
    
    $_SESSION['wbws_calendar_mode'] = $mode;
    return $mode;
}

/**
 * Format a date respecting the system calendar setting
 * 
 * @param mixed $input — DateTime object, date string, or timestamp
 * @param string $format — 'short', 'medium', 'long', 'full', or Ethiopian format tokens
 * @param mysqli|null $conn — DB connection (optional if session cached)
 * @return string
 */
function wbws_format_date($input, $format = 'medium', $conn = null) {
    if (empty($input)) return '—';
    
    $mode = wbws_get_calendar_mode($conn);
    
    // Parse input to DateTime
    if ($input instanceof DateTime) {
        $dt = $input;
    } else {
        try {
            $dt = new DateTime($input, new DateTimeZone('Africa/Addis_Ababa'));
        } catch (Exception $e) {
            return (string)$input;
        }
    }
    
    if ($mode === 'ethiopian') {
        return wbws_format_ethiopian($dt, $format);
    } else {
        return wbws_format_gregorian($dt, $format);
    }
}

function wbws_format_ethiopian($dt, $format) {
    $ec = gregorian_to_ethiopian($dt);
    $months_am = ['', 'መስከረም', 'ጥቅምት', 'ህዳር', 'ታህሳስ', 'ጥር', 'የካቲት',
                  'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'];
    $months_short = ['', 'Mes', 'Tik', 'Hid', 'Tah', 'Tir', 'Yek',
                     'Meg', 'Miy', 'Gin', 'Sen', 'Ham', 'Neh', 'Pag'];
    $months_en = ['', 'Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit',
                  'Megabit', 'Miyazya', 'Ginbot', 'Sene', 'Hamle', 'Nehasse', 'Pagume'];
    
    switch ($format) {
        case 'short':
            return sprintf('%02d/%02d/%d', $ec['day'], $ec['month'], $ec['year']);
        case 'medium':
            return $months_short[$ec['month']] . ' ' . $ec['day'] . ', ' . $ec['year'];
        case 'long':
            return $months_am[$ec['month']] . ' ' . $ec['day'] . ', ' . $ec['year'] . ' ዓ.ም.';
        case 'full':
            return $months_am[$ec['month']] . ' ' . $ec['day'] . ', ' . $ec['year'] . ' ዓ.ም.';
        case 'month-year':
            return $months_am[$ec['month']] . ' ' . $ec['year'];
        default:
            // Support ethiopian_date format tokens: F j, Y etc
            return ethio_date_format($dt, $format);
    }
}

function wbws_format_gregorian($dt, $format) {
    switch ($format) {
        case 'short':
            return $dt->format('d/m/Y');
        case 'medium':
            return $dt->format('M j, Y');
        case 'long':
            return $dt->format('F j, Y');
        case 'full':
            return $dt->format('l, F j, Y');
        case 'month-year':
            return $dt->format('F Y');
        default:
            return $dt->format($format);
    }
}

/**
 * Get the script tags needed for the calendar system
 * Include this in the <head> of every dashboard
 */
function wbws_calendar_scripts($conn = null) {
    $mode = wbws_get_calendar_mode($conn);
    return "<script>const WBWS_CALENDAR_MODE='" . htmlspecialchars($mode, ENT_QUOTES) . "';</script>\n" .
           "<script src=\"/admin/js/wbws-calendar.js\"></script>\n";
}

/**
 * Get the Noto Sans Ethiopic font import (needed for Amharic dates)
 */
function wbws_font_import() {
    return "@import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;600;700&display=swap');";
}
