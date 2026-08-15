<?php
$_SERVER['DOCUMENT_ROOT'] = 'd:/FKSS';
session_start();
$_SESSION['admin_username'] = 'cli';
require 'd:/FKSS/admin/migrations/006_add_temporary_members_tier.php';
echo $res->fetch_row()[1];
