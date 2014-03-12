<?php

$REALRUN = true;
$course_id = 5;

define('CLI_SCRIPT', true);
require_once("config.php");

$CFG->pdo = 'mysql:host='.$CFG->dbhost.';port=3306;dbname='.$CFG->dbname;
// echo($CFG->pdo);
$p = $CFG->prefix;

require_once("pdo.php");
var_dump($pdo);

// Load all the users with grade in the class and get the email
$users = array();
$stmt = pdoQueryDie($pdo,
    "SELECT DISTINCT userid, email
        FROM {$p}grade_items_history AS IH
        LEFT JOIN {$p}grade_grades_history AS GH 
        ON IH.oldid = itemid
        JOIN {$p}user AS U
        ON GH.userid = U.id
        WHERE courseid = :CID",
    array(":CID" => $course_id));

while ( $row = $stmt->fetch(PDO::FETCH_ASSOC) ) {
    if ( strlen($row['userid']) < 1 ) continue;
    $users[intval($row['userid'])] = $row['email'];
}
ksort($users);

$count = 0;
foreach ($users as $userid => $email) {
    // if ( $userid != 1992 ) continue;
    echo("========= User: $userid $email ==========\n");

    // Find the "course total" row
    $stmt = pdoQueryDie($pdo,
        "SELECT GG.id AS id, finalgrade
            FROM {$p}grade_grades AS GG
            JOIN {$p}grade_items AS GI
            ON GG.itemid = GI.id
            WHERE userid = :UID AND courseid = :CID AND rawgrade IS NULL",
        array(":CID" => $course_id, ":UID" => $userid));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ( $row === false ) continue;

    $coursegrade = $row['finalgrade'];
    $coursegradeid = $row['id'];

    // Compute what the total should be
    $stmt = pdoQueryDie($pdo,
        "SELECT sum(finalgrade) AS finalgrade 
            FROM {$p}grade_grades AS GG
            JOIN {$p}grade_items AS GI
            ON GG.itemid = GI.id
            WHERE userid = :UID AND courseid = :CID AND rawgrade IS NOT NULL",
        array(":CID" => $course_id, ":UID" => $userid));
    
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ( $row === false ) continue;
    $newgrade = $row['finalgrade'];

    if ( $coursegrade >= $newgrade ) continue;

    // Time to patch it up...
    echo("User=$email Userid=$userid Old=$coursegrade New=$newgrade ID=$coursegradeid \n");

    if ( $REALRUN ) {
        $stmt = pdoQueryDie($pdo,
            "UPDATE {$p}grade_grades SET finalgrade = :FG
                WHERE id = :GID",
            array(":FG" => $newgrade, ":GID" => $coursegradeid));
    }

}
