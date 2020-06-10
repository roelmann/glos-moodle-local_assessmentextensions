<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
* A scheduled task for scripted database integrations.
*
* @package    local_assessmentextensions - template
* @copyright  2016 ROelmann
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

namespace local_assessmentextensions\task;
use stdClass;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot.'/local/extdb/classes/task/extdb.php');

/**
* A scheduled task for scripted external database integrations.
*
* @copyright  2016 ROelmann
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
class assessmentextensions extends \core\task\scheduled_task {
   
   /**
   * Get a descriptive name for this task (shown to admins).
   *
   * @return string
   */
   public function get_name() {
      return get_string('pluginname', 'local_assessmentextensions');
   }
   
   /**
   * Run sync.
   */
   public function execute() {
      echo "start" . date("l jS \of F Y h:i:s A\n\n");
      global $CFG, $DB;
      $submissiontime = date('H:i:s', strtotime('3pm'));
      $fbduetime = date('H:i:s', strtotime('9am'));
      
      $externaldb = new \local_extdb\extdb();
      $name = $externaldb->get_name();
      
      $externaldbtype = $externaldb->get_config('dbtype');
      $externaldbhost = $externaldb->get_config('dbhost');
      $externaldbname = $externaldb->get_config('dbname');
      $externaldbencoding = $externaldb->get_config('dbencoding');
      $externaldbsetupsql = $externaldb->get_config('dbsetupsql');
      $externaldbsybasequoting = $externaldb->get_config('dbsybasequoting');
      $externaldbdebugdb = $externaldb->get_config('dbdebugdb');
      $externaldbuser = $externaldb->get_config('dbuser');
      $externaldbpassword = $externaldb->get_config('dbpass');
      $tableassm = get_string('assessmentstable', 'local_assessmentextensions');
      $tablegrades = get_string('stuassesstable', 'local_assessmentextensions');
      
      // Database connection and setup checks.
      // Check connection and label Db/Table in cron output for debugging if required.
      if (!$externaldbtype) {
         echo 'Database not defined.<br>';
         return 0;
      } else {
         echo 'Database: ' . $externaldbtype . '<br>';
      }
      // Check remote assessments table - usr_data_assessments.
      if (!$tableassm) {
         echo 'Assessments Table not defined.<br>';
         return 0;
      } else {
         echo 'Assessments Table: ' . $tableassm . '<br>';
      }
      // Check remote student grades table - usr_data_student_assessments.
      if (!$tablegrades) {
         echo 'Student Grades Table not defined.<br>';
         return 0;
      } else {
         echo 'Student Grades Table: ' . $tablegrades . '<br>';
      }
      echo 'Starting connection...<br>';
      
      // Report connection error if occurs.
      if (!$extdb = $externaldb->db_init(
         $externaldbtype,
         $externaldbhost,
         $externaldbuser,
         $externaldbpassword,
         $externaldbname)) {
            echo 'Error while communicating with external database <br>';
            return 1;
         }
         
         $extensions = array();
         // Read grades and extensions data from external table.
         /********************************************************
         * ARRAY                                                *
         *     id                                               *
         *     student_code                                     *
         *     assessment_idcode                                *
         *     student_ext_duedate                               *
         *     student_ext_duetime                              *
         *     student_fbdue_date                               *
         *     student_fbdue_time                               *
         ********************************************************/
         $sql = $externaldb->db_get_sql($tablegrades, array(), array(), true);
         if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
               while ($fields = $rs->FetchRow()) {
                  $fields = array_change_key_case($fields, CASE_LOWER);
                  $fields = $externaldb->db_decode($fields);
                  $extensions[] = $fields;
               }
            }
            $rs->Close();
         } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external course table<br>';
            return 4;
         }
         
         // Create reference array of students - if has a linked assessement AND an extension date/time.
         $student = array();
         foreach ($extensions as $e) {
            /*              echo "\n\n-------------- DEBUG ---------\n\n"; 
            echo date("l jS \of F Y h:i:s A\n\n");
            var_dump($e);
            echo "\n\n------------ END DEBUG -----------\n\n"; 
            */
            $key = $e['student_code'].$e['assessment_idcode'];
            if ($e['assessment_idcode'] && ($e['student_ext_duedate'] || $e['student_ext_duetime'])) {
               $student[$key]['stucode'] = $e['student_code'];
               $student[$key]['lc'] = $e['assessment_idcode'];
               $student[$key]['extdate'] = $e['student_ext_duedate'];
               $student[$key]['exttime'] = $e['student_ext_duetime'];
               $student[$key]['fbdate'] = $e['student_fbdue_date'];
               $student[$key]['fbtime'] = $e['student_fbdue_time'];
            }
         }
         
         // Get students who have late submissions but no extensions.
         // Students with an extension (even if late) are caught in the main section of this task.
         // $sqllates = "SELECT a.assessment_idcode, CONCAT('s', sa.student_code) as student_code,
         $sqllates = "SELECT a.assessment_idcode, sa.student_code as student_code,
         a.assessment_duedate as duedate, a.assessment_duetime as duetime, sa.received_date,
         sa.received_time, sa.student_fbdue_date, sa.student_fbdue_time
         FROM ".$tablegrades." sa
         JOIN ".$tableassm." a ON sa.assessment_idcode = a.assessment_idcode
         WHERE sa.student_ext_duedate IS NULL
         AND sa.received_date > a.assessment_duedate;";
         if ($rs = $extdb->Execute($sqllates)) {
            if (!$rs->EOF) {
               while ($fields = $rs->FetchRow()) {
                  $fields = array_change_key_case($fields, CASE_LOWER);
                  $fields = $externaldb->db_decode($fields);
                  $lates[] = $fields;
               }
            }
            $rs->Close();
         } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external course table 2<br>';
            return 4;
         }
         // Create reference array of late students - if does not have an extension date/time.
         $latestudent = array();
         foreach ($lates as $l) {
            $key = $l['student_code'].$l['assessment_idcode'];
            if ($l['assessment_idcode'] ) {
               $latestudent[$key]['stucode'] = $l['student_code'];
               $latestudent[$key]['lc'] = $l['assessment_idcode'];
               $latestudent[$key]['duedate'] = $l['duedate'];
               $latestudent[$key]['duetime'] = $l['duetime'];
               $latestudent[$key]['fbdate'] = $l['student_fbdue_date'];
               $latestudent[$key]['fbtime'] = $l['student_fbdue_time'];
            }
         }
         // print_r($lates);
         // print_r($latestudent);
         
         // Set extensions.
         // Echo statements output to cron or when task run immediately for debugging.
         foreach ($student as $k => $v) {
            if (!empty($student[$k]['extdate'])) {
               // Create array for writing values.
               $userflags = new stdClass();
               $useroverrides = new stdClass();
               // Set user.
               /*             $username = 's'.$student[$k]['stucode'];
               if ($student[$k]['stucode'] < 1000000) {
                  $username = 's0'.$student[$k]['stucode'];
               } */
               $username = $student[$k]['stucode'];
               while (strlen($username) < 7){
                  $username = '0' . $username;
               }
               if (strlen($username) != 7 ) {
                  echo 'Not 7 char: ' . $username;
               }
               $username = 's' . $username;
               // echo '<br><p>username line 219: '.$username.' </p>';
               // Set username (student number).
               $userflags->userid = $DB->get_field('user', 'id', array('username' => $username));
               $useroverrides->userid = $userflags->userid;
               // echo '<br><p>userflag->userid'.$userflags->userid.' #:</p>';
               // Set assignment id.
               $userflags->assignment = $DB->get_field('course_modules', 'instance', array('idnumber' => $student[$k]['lc']));
               $useroverrides->assignid = $userflags->assignment;
               // Set extension date.
               if (strpos($student[$k]['lc'], '18/19') > 1) {
                  //    echo '18/19 HANDIN 6pm<br>' . "\n";
                  $submissiontime = date('H:i:s', strtotime('6pm'));
               }
               $extdate = $student[$k]['extdate'];
               $exttime = $submissiontime;
               $fbduedate = $student[$k]['fbdate'];
               // Convert extension date and time to Unix time stamp.
               $exttimestamp = strtotime($extdate.' '.$exttime);
               $fbduetimestamp = strtotime($fbduedate.' '.$fbduetime);
               $userflags->extensionduedate = $exttimestamp;
               $useroverrides->duedate = $exttimestamp;
               $useroverrides->cutoffdate = $fbduetimestamp;
               // Error trap to make sure assignment and user are set - or ignore.
               if (!empty($userflags->assignment) && !empty($userflags->userid)) {
                  // Check if record exists already.
                  if ($DB->record_exists('assign_user_flags',
                  array('userid' => $userflags->userid, 'assignment' => $userflags->assignment))) {
                     // Set id as unique key.
                     $userflags->id = $DB->get_field('assign_user_flags', 'id',
                     array('userid' => $userflags->userid, 'assignment' => $userflags->assignment));
                     // Check existing extension date if set.
                     $extdue = $DB->get_field('assign_user_flags', 'extensionduedate',
                     array('userid' => $userflags->userid, 'assignment' => $userflags->assignment));
                     // If the extension date is different then update the one on Moodle to be the same as the SITS date.
                     if ($extdue != $userflags->extensionduedate) {
                        $DB->update_record('assign_user_flags', $userflags, false);
                        //  echo $username.' updated<br>';
                     }
                  } else { // If no record exists.
                     // Set other default values - 0 if new record.
                     $userflags->locked = 0;
                     $userflags->mailed = 0;
                     $userflags->workflowstate = 0;
                     $userflags->allocatedmarker = 0;
                     // Create new record with extensions.
                     $DB->insert_record('assign_user_flags', $userflags, false);
                     //   echo $username.' created<br>';
                  }
               }
               // reset submission time to 3pm
               $submissiontime = date('H:i:s', strtotime('3pm'));
               
               // Also populate assign_overrides.
               if (!empty($useroverrides->assignid) && !empty($useroverrides->userid) && !empty($useroverrides->duedate)) {
                  // Check if record exists already.
                  if ($DB->record_exists('assign_overrides',
                  array('userid' => $useroverrides->userid, 'assignid' => $useroverrides->assignid))) {
                     // Set id as unique key.
                     $useroverrides->id = $DB->get_field('assign_overrides', 'id',
                     array('userid' => $useroverrides->userid, 'assignid' => $useroverrides->assignid));
                     // Check existing extension date if set.
                     $extdue = $DB->get_field('assign_overrides', 'duedate',
                     array('userid' => $useroverrides->userid, 'assignid' => $useroverrides->assignid));
                     $fbdue = $DB->get_field('assign_overrides', 'cutoffdate',
                     array('userid' => $useroverrides->userid, 'assignid' => $useroverrides->assignid));
                     // If the extension date is different then update the one on Moodle to be the same as the SITS date.
                     if ($extdue != $useroverrides->duedate || $fbdue != $useroverrides->cutoffdate) {
                        $DB->update_record('assign_overrides', $useroverrides, false);
                        // echo $username.' updated<br>';
                     }
                  } else { // If no record exists.
                     // Set other default values - 0 if new record.
                     // Create new record with extensions.
                     $DB->insert_record('assign_overrides', $useroverrides, false);
                     // echo $username.' created<br';
                  }
               }
            }
         }
         
         // Set late adjustments to feedback return date where student has no extension.
         // Echo statements output to cron or when task run immediately for debugging.
         foreach ($latestudent as $k => $v) {
            $userflags = new stdClass();
            $useroverrides = new stdClass();
            
            // Set user.
            $username = $latestudent[$k]['stucode'];
            
            while (strlen($username) < 7){
               $username = '0' . $username;
            }
            if (strlen($username) != 7 ) {
               echo 'Lates - Not 7 char: ' . $username;
            }
            $username = 's' . $username;
            
            //  echo '<br><p>username - Lates: '.$username.' </p>';
            // Set username (student number).
            $useroverrides->userid = $DB->get_field('user', 'id', array('username' => $username));
            //  echo '<p>useroverrides->userid'.$useroverrides->userid.' #:</p>';
            // Set assignment id.
            $useroverrides->assignid = $DB->get_field('course_modules', 'instance', array('idnumber' => $latestudent[$k]['lc']));
            // Get dates.
            $fbduedate = $latestudent[$k]['fbdate'];
            
            if(!array_key_exists($k, $latestudent))
            {
               echo "ERROR: $k doesn't exist in student array\n";
            }
            else
            {
               
               $fbduetime = $latestudent[$k]['fbtime'];
            }
            // Convert dates and time to Unix time stamp.
            $duetimestamp = strtotime($latestudent[$k]['duedate'].' '.$latestudent[$k]['duetime']);
            $fbduetimestamp = strtotime($fbduedate.' '.$fbduetime);
            $useroverrides->duedate = $duetimestamp;
            $useroverrides->cutoffdate = $fbduetimestamp;
            // Populate assign_overrides.
            if (!empty($useroverrides->assignid) && !empty($useroverrides->userid) && !empty($useroverrides->duedate)) {
               // Check if record exists already.
               if ($DB->record_exists('assign_overrides',
               array('userid' => $useroverrides->userid, 'assignid' => $useroverrides->assignid))) {
                  // Set id as unique key.
                  $useroverrides->id = $DB->get_field('assign_overrides', 'id',
                  array('userid' => $useroverrides->userid, 'assignid' => $useroverrides->assignid));
                  // Check existing extension date if set.
                  $extdue = $DB->get_field('assign_overrides', 'duedate',
                  array('userid' => $useroverrides->userid, 'assignid' => $useroverrides->assignid));
                  $fbdue = $DB->get_field('assign_overrides', 'cutoffdate',
                  array('userid' => $useroverrides->userid, 'assignid' => $useroverrides->assignid));
                  // If the extension date is different then update the one on Moodle to be the same as the SITS date.
                  if ($extdue != $useroverrides->duedate || $fbdue != $useroverrides->cutoffdate) {
                     $DB->update_record('assign_overrides', $useroverrides, false);
                     //   echo $username.' updated<br>';
                  }
               } else { // If no record exists.
                  // Set other default values - 0 if new record.
                  // Create new record with extensions.
                  $DB->insert_record('assign_overrides', $useroverrides, false);
                  // echo $username.' created<br';
               }
            }
         }
         
         // Reset change flags.
         $sql = "UPDATE " . $tablegrades . " SET assessment_changebydw = 0 WHERE assessment_changebydw = 1;";
         $extdb->Execute($sql);
         $sql = "UPDATE " . $tableassm . " SET assessment_changebydw = 0 WHERE assessment_changebydw = 1;";
         $extdb->Execute($sql);
         
         // Free memory.
         $extdb->Close();
         echo "end" . date("l jS \of F Y h:i:s A\n\n");
      }
      
   }
   