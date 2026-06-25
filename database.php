<?php

$serverName = "203.154.130.236";
$connectionOptions = [
    "Database" => "all_report",
    "Uid" => "sa",
    "PWD" => "Journal@25",
    "CharacterSet" => "UTF-8"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}