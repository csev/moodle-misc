<?php

$REALRUN = true;
$course_id = 5;

define('CLI_SCRIPT', true);
require_once("config.php");

$CFG->pdo = 'mysql:host='.$CFG->dbhost.';port=3306;dbname='.$CFG->dbname;
$p = $CFG->prefix;

// I use PDO because I like it - Yeah I know it is not Moodle-Like
require_once("pdo.php");
var_dump($pdo);

// Load up the schema for the grade_grades table
$metadata = pdoMetadata($pdo, "{$p}grade_grades");
$fields = array();
foreach($metadata as $colarray) {
    if ( $colarray[0] == 'id' ) continue;
    $fields[] = $colarray[0];
}

// Load all the active grade book items - including item name
$items = array();
$stmt = pdoQueryDie($pdo,
    "SELECT id, itemname FROM {$p}grade_items WHERE courseid = :CID",
    array(":CID" => $course_id));

while ( $row = $stmt->fetch(PDO::FETCH_ASSOC) ) {
    $items[$row['id']] = $row['itemname'];
}
// var_dump($items);

// Get all the users that have grades in the class
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

// Start the log (appending)
$handle = fopen("fixgrade.log", "a+");

// Mark them as all having the same time created - this makes
// it easier to delete all the inserted records if they all
// have the same timecreated field
$time_now = time();
echo("Timestamp: $time_now \n");
fwrite($handle,"Timestamp: $time_now \n");

// Loop through - we use nested loops because we want
// foolproof logic and precise start/stop ability 
// This does not have to be efficient - it has to be perfect
// Loop through each user and each gradable item for each user
$count = 0;
foreach ($users as $userid => $email) {
    echo("========= User: $userid $email ==========\n");
    foreach ( $items as $itemid => $itemname ) {
        if ( strlen($itemname) < 1 ) continue;

        // Find the highest grade for the user in the 
        // history table - we do this in a loop because
        // we also need the id column from that row
        // (i.e. we cannot use MAX() and GROUP_BY)
        $stmt = pdoQueryDie($pdo,
            "SELECT GH.id AS id, rawgrade
                FROM {$p}grade_items_history AS IH 
                LEFT JOIN {$p}grade_grades_history AS GH 
                ON IH.oldid = itemid
                WHERE itemname = :INA AND courseid = :CID AND userid = :UID",
        array(":INA" => $itemname, ":CID" => $course_id, ":UID" => $userid));
        $maxgrade = -1.0;
        $maxgradeid = false;
        while ( $row = $stmt->fetch(PDO::FETCH_ASSOC) ) {
            if ( $maxgrade < $row['rawgrade'] ) {
                $maxgrade = $row['rawgrade'];
                $maxgradeid = $row['id'];
            }
        }
        if ( $maxgrade > 0.0 ) {
            // echo "==== ",$itemname,' id=',$gradeid,' ', $maxgrade,"\n";
        } else {
            // echo "==== ",$itemname,' has no grade history', "\n";
            continue;
        }

        // Find the current grade for the item in the active
        // grades table
        $stmt = pdoQueryDie($pdo,
            "SELECT G.id AS id, rawgrade
                FROM {$p}grade_items AS I 
                LEFT JOIN {$p}grade_grades AS G 
                ON I.id = itemid
                WHERE itemname = :INA AND courseid = :CID AND userid = :UID",
        array(":INA" => $itemname, ":CID" => $course_id, ":UID" => $userid));
        
        $grade = -1.0;
        $gradeid = false;
        while ( $row = $stmt->fetch(PDO::FETCH_ASSOC) ) {
            $grade = $row['rawgrade'];
            $gradeid = $row['id'];
        }
        
        if ( $grade >= $maxgrade ) {
            // echo("Userid=$userid grades match.\n");
            continue;
        } else {
            echo("Userid=$userid Email=$email Item=$itemname Actual=$grade Max=$maxgrade Item=$itemid Gradeid=$gradeid \n");
        }

        if ( strlen($gradeid) > 1 ) {
            echo("Userid=$userid Email=$email Item=$itemname Actual=$grade Max=$maxgrade Item=$itemid Gradeid=$gradeid \n");
            die("Found a record that needs to be updated");
        }

        // Time to fix things up - grab all the fields from that
        // "max" row from  grade_history
        $stmt = pdoQueryDie($pdo,
            "SELECT * FROM {$p}grade_grades_history WHERE id = :ID",
        array(":ID" => $maxgradeid));

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ( $row === false ) {
            echo("Userid=$userid Email=$email Item=$itemname Actual=$grade Max=$maxgrade Item=$itemid Gradeid=$gradeid \n");
            die("Could not load grade history id=$maxgradeid");
        }

        // Adjust the grade_history data so it will slide into
        // the active grade table
        unset($row['id']);
        unset($row['action']);
        unset($row['oldid']);
        unset($row['source']);
        unset($row['loggeduser']);
        $row['itemid'] = $itemid;
        // All inserted rows have the same timecreated to allow for easy delete
        $row['timecreated'] = $time_now;
        // var_dump($row);

        // Sanity check that we have the right columns in grades and grades_history
        foreach ($row as $col => $val) {
            if ( !in_array($col, $fields) ) {
                var_dump($fields);
                var_dump($row);
                die("Found $col in grades_history not in grades");
            }
        }

        // Construct the INSERT statement
        $fieldstr = "";
        $valuestr = "";
        $values = array();
        foreach ( $fields as $field ) {
            if ( ! array_key_exists($field, $row) ) {
                var_dump($fields);
                var_dump($row);
                die("Could not find $field in grades_history");
            }
            if ( strlen($fieldstr) > 1 ) $fieldstr .= ', ';
            if ( strlen($valuestr) > 1 ) $valuestr .= ', ';
            $fieldstr .= $field;
            $valuestr .= ':'.$field;
            $values[':'.$field] = $row[$field];
        }

        $sql = "INSERT INTO {$p}grade_grades ( $fieldstr ) VALUES ( $valuestr )";

        fwrite($handle, "==== Userid=$userid Email=$email Item=$itemname Actual=$grade Max=$maxgrade Item=$itemid Gradeid=$gradeid \n");
        ob_start();
        var_dump($values);
        $result = ob_get_clean();
        fwrite($handle, $result);

        if ( $REALRUN ) {
            $stmt = pdoQueryDie($pdo,$sql,$values);
        }

        $count ++ ;
        // Allows one to run partial runs for debugging..
        // if ( $count > 10 ) die();
    }
}
