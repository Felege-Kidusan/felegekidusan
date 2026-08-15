<?php
$hrFile = __DIR__ . '/admin/dashboards/hr-dept.php';
$hrContent = file_get_contents($hrFile);
$hrContent = str_replace('Information Department', 'HR Department', $hrContent);
$hrContent = str_replace('info_register_member', 'hr_register_member', $hrContent);
$hrContent = str_replace('<?= DEPT_INFO_NAME_EN ?>', 'HR Department', $hrContent);
$hrContent = str_replace('<?= DEPT_INFO_NAME ?>', 'የሰው ሀይል አስተዳደር (HR)', $hrContent);
$hrContent = str_replace('የመረጃ ቁጥጥር · መመዝገብ · ሪፖርት', 'የሰው ሀይል አስተዳደር · መመዝገብ · መቆጣጠር', $hrContent);
$hrContent = str_replace('የመረጃ ቁጥጥር · የአባላት መመዝገብ እና ሪፖርት', 'የሰው ሀይል አስተዳደር · የአባላት መመዝገብ እና መቆጣጠር', $hrContent);
file_put_contents($hrFile, $hrContent);

$infoFile = __DIR__ . '/admin/dashboards/info-dept.php';
$infoContent = file_get_contents($infoFile);
$infoContent = preg_replace('/<button data-section="idcards".*?<\/button>/s', '', $infoContent);
$infoContent = preg_replace('/<a href="\/admin\/groups\.php".*?<\/a>/s', '', $infoContent);
$infoContent = preg_replace('/<button type="button"[^>]*onclick="toggleMemberRegistrationForm\(true\)"[^>]*>.*?<\/button>/s', '<!-- Registration moved to HR Dept -->', $infoContent);
$infoContent = preg_replace('/<div id="memberRegistrationWrapper".*?<!-- 4\. MEDICAL & HEALTH INFO -->.*?<\/div>\s*<\/div>\s*<\/div>/s', '<!-- Registration moved to HR -->', $infoContent);
file_put_contents($infoFile, $infoContent);
echo "Done";
