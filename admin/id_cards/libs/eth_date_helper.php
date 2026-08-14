<?php
// FILE: /admin/id_cards/libs/eth_date_helper.php

function toEthiopianDate($gregorianDate) {
    if (!$gregorianDate) return "---";
    
    $timestamp = strtotime($gregorianDate);
    $gYear = date('Y', $timestamp);
    $gMonth = date('n', $timestamp);
    $gDay = date('j', $timestamp);

    // Simple conversion logic (approximate but effective for display)
    // For exact liturgical dates, a more complex library is needed, 
    // but this works for standard civil dates.
    
    $ethiopianMonths = [
        1 => 'መስከረም', 2 => 'ጥቅምት', 3 => 'ህዳር', 4 => 'ታህሳስ', 
        5 => 'ጥር', 6 => 'የካቲት', 7 => 'መጋቢት', 8 => 'ሚያዝያ', 
        9 => 'ግንቦት', 10 => 'ሰኔ', 11 => 'ሐምሌ', 12 => 'ነሐሴ', 13 => 'ጳጉሜ'
    ];

    // Magic number offset for Ethiopian Calendar
    // This is a simplified algorithm.
    $ethYear = $gYear - 8;
    
    // Logic to switch year after Sept 11
    // (This is a basic implementation suitable for ID cards)
    if ($gMonth < 9 || ($gMonth == 9 && $gDay < 11)) {
        $ethYear -= 1;
    }

    // Calculate Month and Day roughly
    // To keep it robust without 100 lines of math, we will use the 
    // "Gregorian Date - 7/8 years" rule for the year, and PHP's timestamp 
    // to format the day/month names in Amharic manually if needed, 
    // BUT requested is "Ethiopian Calendar".
    
    // Let's use a standard conversion offset for display accuracy:
    $jd_offset = 1723856;
    $jdn = cal_to_jd(CAL_GREGORIAN, $gMonth, $gDay, $gYear);
    $r = ($jdn - $jd_offset) % 1461;
    $n = ($r % 365) + 365 * intval($r / 1460);
    
    $eyear = 4 * intval(($jdn - $jd_offset) / 1461) + intval($r / 365) - intval($r / 1460);
    $emonth = intval($n / 30) + 1;
    $eday = ($n % 30) + 1;

    return isset($ethiopianMonths[$emonth]) 
        ? "$ethiopianMonths[$emonth] $eday ቀን $eyear ዓ.ም" 
        : "$eday/$emonth/$eyear";
}

// Function to check expiry
function isExpired($generatedDate) {
    if (!$generatedDate) return false;
    $gen = new DateTime($generatedDate);
    $exp = clone $gen;
    $exp->modify('+4 years'); // 4 Years Validity
    return (new DateTime() > $exp);
}
?>