<?php

if (!file_exists(__DIR__ . '/../config/database.php')) {
    die("database.php NOT FOUND");
} else {
    echo "database.php FOUND";
}