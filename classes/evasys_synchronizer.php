<?php
// This file is part of the Moodle plugin block_evasys_sync
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

namespace block_evasys_sync;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . "/local/lsf_unification/lib_his.php");
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/lib.php');

class evasys_synchronizer {
    private $courseid;
    protected $soapclient;
    private $blockcontext;
    private $courseinformation;
    private $lsfcourses;

    public function __construct($courseid) {
        $this->courseid = $courseid;
        $this->init_soap_client();
        $this->blockcontext = \context_course::instance($courseid); // TODO Course context or block context? Check caps.
        $this->courseinformation = $this->get_course_information();
    }

    public function get_courses_from_lsf() {
        global $DB;
        if ($this->lsfcourses !== null) {
            return $this->lsfcourses;
        }
        $course = get_course($this->courseid);

        // Fetch veranstnr from LSF view.
        if ($course->idnumber) {
            establish_secondary_DB_connection();
            $lsfentry = get_course_by_veranstid(intval($course->idnumber));
            close_secondary_DB_connection();

            if (!is_object($lsfentry)) {
                throw new \Exception('Cannot sync: Connection to LSF could not be established. Please try again later.');
            }
            $maincourse = trim($lsfentry->veranstid);
        }
        // Fetch persistent object id.
        $pid = $DB->get_field('block_evasys_sync_courses', 'id', array('course' => $this->courseid));
        // Get all associated courses.
        if (!$pid === false) {
            $extras = new \block_evasys_sync\course_evasys_courses_allocation($pid);
            $extras = explode('#', $extras->get('evasyscourses'));
        } else {
            $extras = [];
        }
        // If noone has associated the course itself, we force that.
        if (isset($maincourse) && !empty($maincourse)) {
            if (!in_array($maincourse, $extras)) {
                $extras[] = $maincourse;
            }
        }
        $extras = array_filter($extras);
        establish_secondary_DB_connection();
        // Fetch metadata (id, title) for the courses.
        $result = array();
        foreach ($extras as $course) {
            $courseinfo = get_course_by_veranstid(intval($course));
            $result[] = array(
                'title' => $courseinfo->titel,
                'id' => trim($courseinfo->veranstnr) . ' ' . trim($courseinfo->semestertxt));
        }
        close_secondary_DB_connection();
        $this->lsfcourses = $result;
        return $this->lsfcourses;
    }

    private function init_soap_client() {
        $this->soapclient = new \SoapClient(get_config('block_evasys_sync', 'evasys_wsdl_url'), [
            'trace' => 1,
            'exceptions' => 0,
            'location' => get_config('block_evasys_sync', 'evasys_soap_url')
        ]);

        $headerbody = new \SoapVar([
            new \SoapVar(get_config('block_evasys_sync', 'evasys_username'), XSD_STRING, null, null, 'Login', null),
            new \SoapVar(get_config('block_evasys_sync', 'evasys_password'), XSD_STRING, null, null, 'Password', null),
        ], SOAP_ENC_OBJECT);
        $header = new \SOAPHEADER('soap', 'Header', $headerbody);
        $this->soapclient->__setSoapHeaders($header);
    }

    private function get_course_information() {
        $result = [];
        foreach ($this->get_courses_from_lsf() as $course) {
            $soapresult = $this->soapclient->GetCourse($course['id'], 'PUBLIC', true, true);
            if (is_soap_fault($soapresult)) {
                // This happens e.g. if there is no corresponding course in EvaSys.
                return null;
            }
            $result[$course['id']] = $soapresult;
        }
        return $result;
    }

    /**
     * Builds array with all surveys and additional information to surveys
     * @return array of surveys with additional information
     */
    public function get_surveys($courseid) {
        if ($this->courseinformation[$courseid] === null) {
            return array();
        }
        if (!isset($this->courseinformation[$courseid]->m_oSurveyHolder->m_aSurveys->Surveys)) {
            return array();
        }
        $rawsurveys = $this->courseinformation[$courseid]->m_oSurveyHolder->m_aSurveys->Surveys;
        if (count((array)$rawsurveys) == 0) {
            // No surveys available.
            return array();
        }

        if (is_object($rawsurveys)) {
            // Course only has one associated survey.
            return [$this->enrich_survey($rawsurveys)];
        }

        $enrichedsurveys = array();

        foreach ($rawsurveys as &$survey) {
            $enrichedsurveys[] = $this->enrich_survey($survey);
        }
        return $enrichedsurveys;
    }

    public function get_all_surveys() {
        // Gets all surveys from the associated evasys courses.
        $surveys = [];
        foreach ($this->lsfcourses as $course) {
            $surveys = array_merge($surveys, $this->get_surveys($course['id']));
        }
        return $surveys;
    }

    public function get_course_name($coursekey) {
        if (isset($this->courseinformation[$coursekey])) {
            return $this->courseinformation[$coursekey]->m_sCourseTitle;
        }
        return "Unknown";
    }

    public function get_course_id($coursekey) {
        if (isset($this->courseinformation[$coursekey])) {
            return $this->courseinformation[$coursekey]->m_nCourseId;
        }
        return "Unknown";
    }

    /**
     * Enriches Surveys with Information
     * @param \stdClass $rawsurvey Survey without additional information
     * @return \stdClass Survey with additional information
     */
    private function enrich_survey($rawsurvey) {
        $enrichedsurvey = new \stdClass();
        $enrichedsurvey->id = $rawsurvey->m_nSurveyId;
        $enrichedsurvey->amountOfCompletedForms = $rawsurvey->m_nFormCount;
        $enrichedsurvey->surveyStatus = $this->get_survey_status($rawsurvey->m_nOpenState);
        $enrichedsurvey->formName = $this->get_form_name($rawsurvey->m_nFrmid);
        $enrichedsurvey->formIdPub = $this->get_public_formid($rawsurvey->m_nFrmid);
        $enrichedsurvey->formId = $rawsurvey->m_nFrmid;
        $start = $rawsurvey->m_oPeriod->m_sStartDate;
        $end = $rawsurvey->m_oPeriod->m_sEndDate;
        $enrichedsurvey->startDate = $start;
        $enrichedsurvey->endDate = $end;
        return $enrichedsurvey;
    }

    private function get_survey_status($statusnumber) {
        if ($statusnumber === 1) {
            return 'open';
        } else {
            return 'closed';
        }
    }

    private function get_public_formid($formid) {
        $soapresult = $this->soapclient->GetForm($formid, 'INTERNAL', false);
        $formidpub = $soapresult->FormName;
        return $formidpub;
    }

    private function get_form_name($formid) {
        $soapresult = $this->soapclient->GetForm($formid, 'INTERNAL', false);
        $formname = $soapresult->FormTitle;
        return $formname;
    }

    public function get_amount_participants($courseid) {
        if ($this->courseinformation[$courseid] === null || !property_exists($this->courseinformation[$courseid]->m_aoParticipants, "Persons")) {
            return 0;
        }
        if (is_object($this->courseinformation[$courseid]->m_aoParticipants->Persons)) {
            return 1;
        }

        return count($this->courseinformation[$courseid]->m_aoParticipants->Persons);
    }

    /**
     * Gets all email addresses of enrolled students.
     * @return array of e-mail addresses of all enrolled students
     */
    private function get_enrolled_student_email_adresses_from_usernames() {
        $emailadresses = array();

        $enrolledusers = get_users_by_capability($this->blockcontext, 'block/evasys_sync:mayevaluate');

        foreach ($enrolledusers as $user) {
            array_push($emailadresses, $user->username . "@uni-muenster.de");
        }

        return $emailadresses;
    }

    /**
     * Updates the students who can participate in the survey.
     */
    public function sync_students() {
        if ($this->courseinformation === null) {
            throw new \Exception('Cannot sync: Course not known to EvaSys');
        }

        $emailadresses = $this->get_enrolled_student_email_adresses_from_usernames();
        $students = array();

        foreach ($emailadresses as $emailadress) {
            $soapmsidentifier = new \SoapVar($emailadress, XSD_STRING, null, null, 'm_sIdentifier', null);
            $soapmsemail = new \SoapVar($emailadress, XSD_STRING, null, null, 'm_sEmail', null);
            $student = new \SoapVar(array($soapmsidentifier, $soapmsemail), SOAP_ENC_OBJECT, null, null, 'Persons', null);
            array_push($students, $student);
        }
        $personlist = new \SoapVar($students, SOAP_ENC_OBJECT, null, null, 'PersonList', null);
        $this->courseinformation = $this->get_course_information();
        foreach ($this->courseinformation as $course) {
            $soapresult = $this->soapclient->InsertParticipants($personlist, $course->m_sPubCourseId, 'PUBLIC', false);
            $course = $this->soapclient->GetCourse($course->m_sPubCourseId, 'PUBLIC', true, true); // Update usercount.
            $usercountnow = $course->m_nCountStud;
            if (is_array($course->m_oSurveyHolder->m_aSurveys->Surveys)) {
                foreach ($course->m_oSurveyHolder->m_aSurveys->Surveys as $survey) {
                    $id = $survey->m_nSurveyId;
                    $this->soapclient->GetPswdsBySurvey($id, $usercountnow, 1, true, false);
                }
            } else {
                $id = $course->m_oSurveyHolder->m_aSurveys->Surveys->m_nSurveyId;
                $this->soapclient->GetPswdsBySurvey($id, $usercountnow, 1, true, false); // Create new TAN's.
            }
            if (is_soap_fault($soapresult)) {
                throw new \Exception('Sending list of participants to evasys server failed.');
            }
        }
        return $soapresult;
    }

    /**
     * TODO: Method seems to be unused.
     * @param $dates
     * @return string
     * @throws \coding_exception
     */
    public function invite_all($dates) {
        global $USER;
        // Get all surveys of this moodle course.
        $surveys = $this->get_all_surveys();
        $sent = 0;
        $total = 0;
        $reminders = 0;
        $status = "success";
        $today = date("Ymd");
        $surveycount = count($surveys);
        for ($i = 0; $i < $surveycount; $i++) {
            $survey = $surveys[$i];
            if (str_replace("-", "", $dates["start"]) == $today) {
                // If the survey is set to start today we sent our the invites via evasys right away.
                $id = $survey->id;
                $soap = $this->soapclient->sendInvitationToParticipants($id);
                $soap = str_replace(" emails sent successful", "", $soap);
                $sent += intval(explode("/", $soap)[0]);
                $total += intval(explode("/", $soap)[1]);
                $start = time();
                $event = \block_evasys_sync\event\evaluation_opened::create(array(
                    'courseid' => $this->courseid,
                    'other' => array('teacher' => $USER->id, 'evasysid' => $id, 'type' => "manual")
                ));
                $event->trigger();
            } else {
                // If its's set to start on any other date we simply set them to start at that time.
                $start = strtotime($dates["start"]);
            }
            try {
                if ($this->setstartandend($survey->id, $start, strtotime($dates["end"]))) {
                    $reminders++;
                }
            } catch (\InvalidArgumentException $e) {
                if ($e->getMessage() == "Start date is after end date") {
                    $status = "warning";
                } else if ($e->getMessage() == 'Date is in the past') {
                    $status = "rejected";
                } else {
                    throw $e;
                }
            }
        }
        $ids = array();
        foreach ($surveys as $survey) {
            $ids[] = $survey->id;
        }
        if ($status == "success") {
            $event = \block_evasys_sync\event\evaluationperiod_set::create(array(
                'userid' => $USER->id,
                'courseid' => $this->courseid,
                'context' => \context_course::instance($this->courseid),
                'other' => array('surveys' => $ids, 'start' => $dates['start'], 'end' => $dates['end'])
            ));
            $event->trigger();
        }
        $summary = "$status/$sent/$total/$reminders";
        return $summary;
    }

    public function setstartandend ($id, $start, $end) {
        global $DB;
        if (strtotime($start) > strtotime($end) && $start != null && $end != null) {
            throw new \InvalidArgumentException("Start date is after end date");
        }

        $data = new \stdClass();
        $data->course = $this->courseid;
        $data->survey = $id;

        $data->startdate = $start;
        $data->enddate = $end;
        $recordid = $DB->get_record("block_evasys_sync_surveys", array('survey' => $id), 'id', IGNORE_MISSING);
        if (!$recordid) {
            if ($data->startdate < strtotime(date('Y-m-d')) or $data->enddate < strtotime(date('Y-m-d'))) {
                throw new \InvalidArgumentException('Date is in the past');
            }
            $record = new \block_evasys_sync\evaluationperiod_survey_allocation(0, $data);
            $record->create();
            return true;
        } else {
            $record = \block_evasys_sync\evaluationperiod_survey_allocation::get_record((array) $recordid);
            $time = time();
            foreach ($data as $key => $value) {
                if (($key == 'startdate' or $key == 'enddate')
                    and $value < $time and $record->get($key) != $value) {
                    throw new \InvalidArgumentException('Date is in the past');
                }
                if ($record->get($key) != $value) {
                    $record->set($key, $value);
                    $return = true;
                }
            }
            $record->update();
            return false;
        }
    }

    /**
     * Sends an e-mail with the request to start a Evaluation for a course.
     * @throws \Exception when e-mail request fails
     */
    public function notify_evaluation_responsible_person($dates) {
        global $USER;
        $course = get_course($this->courseid);

        $userto = $this->get_assigned_user($course);

        if (!$userto) {
            throw new \Exception('Could not find the specified user to send an email to.');
        }
        $userfrom =& $USER;

        $notifsubject = "Evaluation für '" . $course->fullname . "' geöffnet";

        $notiftext = "Sehr geehrte/r Evaluationskoordinator/in,\r\n\r\n";
        $notiftext .= "Dies ist eine automatisch generierte Mail, ausgelöst dadurch, dass ein Dozent die Evaluation " .
            "der nachfolgenden Veranstaltung aktiviert hat. \r\n".
            "Bitte passen Sie die Evaluationszeiträume gemäß der Wünsche des Dozenten an. \r\n".
            "Bitte versenden Sie die TANs im EvaSys-Menü " .
            "unter dem Menüpunkt 'TANs per E-Mail an Befragte versenden' für die Veranstaltungen.\r\n\r\n";

        $notiftext .= "Gewünschter Evaluationszeitraum: " . $dates["start"] . " - " . $dates["end"] . "\r\n\r\n";

        foreach ($this->courseinformation as $course) {
            $notiftext .= "Name: " . $course->m_sCourseTitle . "\r\n";
            $notiftext .= "EvaSys-ID: " . $course->m_sPubCourseId ."\r\n";
            $notiftext .= "Die Veranstaltung hat folgende Fragebögen:\r\n\r\n";

            $surveys = $this->get_surveys($course->m_sPubCourseId);
            $i = 0;
            foreach ($surveys as &$survey) {
                $notiftext .= "\tFragebogen-ID: " . $survey->formIdPub . " (" . $survey->formId . ")\r\n";
                $notiftext .= "\tFragebogenname: " . $survey->formName . "\r\n\r\n";
                $i++;
            }
        }

        $notiftext .= "Mit freundlichen Grüßen\r\n";
        $notiftext .= "Learnweb-Support";

        $mailresult = email_to_user($userto, $userfrom, $notifsubject, $notiftext, '', '' , '',
            true, $userfrom->email, $userfrom->firstname . " " . $userfrom->lastname);
        if (!$mailresult) {
            throw new \Exception('Could not send e-mail to person responsible for evaluation');
        }
        $event = \block_evasys_sync\event\evaluation_requested::create(array(
            'userid' => $USER->id,
            'courseid' => $this->courseid
        ));
        $event->trigger();
        global $DB;
        $DB->execute("UPDATE {block_evasys_sync_courseeval} SET state = 3 WHERE course = :courseid", ['courseid' => $this->courseid]);
    }

    /**
     * Returns the user to whom the email is sent.
     * @param $course
     * @return bool|\stdClass user
     */
    static public function get_assigned_user($course) {
        global $DB;

        $user = $DB->get_record('block_evasys_sync_categories', array('course_category' => $course->category));
        // Custom user has not been set.
        if (!$user) {
            // Loop through parents.
            $parents = \core_course_category::get($course->category)->get_parents();
            // Start with direct parent.
            for ($i = count($parents) - 1; $i >= 0; $i--) {
                $user = $DB->get_record('block_evasys_sync_categories', array('course_category' => $parents[$i]));
                // Stop if a parent has been assigned a custom user.
                if ($user) {
                    $userto = \core_user::get_user($user->userid);
                    break;
                }
            }
            // Custom user has not been set for parents.
            if (!$user) {
                // User default user.
                $userto = \core_user::get_user(get_config('block_evasys_sync', 'default_evasys_moodleuser'));
            }
        } else {
            // Use custom user of the course category of the course.
            $userto = \core_user::get_user($user->userid);
        }
        return $userto;
    }
}
