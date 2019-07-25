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

namespace block_evasys_sync\task;

defined('MOODLE_INTERNAL') || die();


class update_survey_status extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     * @throws coding_exception
     */
    public function get_name () {
        return get_string('taskname', 'block_evasys_sync');
    }
    /**
     * This will start and close surveys.
     */
    // It's important to note that this might use alot of space and computational time
    // ...if the Plugin data is never deleted. There would be multiple solutions for this.
    // At the moment manual removal is advised. However since you're here I assume you have some problems...
    // My personal advice is to either move records that are in the past to another table, so you can still display the dates,
    // ...or to simply remove a record once the survey is closed.
    public function execute () {
        global $DB;
        $time = time();
        $startcourses = \block_evasys_sync\evaluationperiod_survey_allocation::get_records_select("state < 2 AND startdate <=$time");
        $closecourses = \block_evasys_sync\evaluationperiod_survey_allocation::get_records_select("state < 2 AND enddate <=$time");
        $soap = $this->init_soap_client();
        $courseids = $DB->get_fieldset_sql("SELECT DISTINCT course FROM {block_evasys_sync_surveys} WHERE state = 0 AND startdate <= $time
                                                 UNION
                                                 SELECT DISTINCT course FROM {block_evasys_sync_surveys} WHERE state = 1 AND enddate <= $time");
        foreach ($courseids as $id) {
            $sync = new \block_evasys_sync\evasys_synchronizer($id);
            $sync->sync_students();
        }

        foreach ($startcourses as $record) {
            $soap->sendInvitationToParticipants($record->get("survey"));
        }
        foreach ($closecourses as $record){
            $soap->CloseSurvey($record->get("survey"));
        }
    }

    private function init_soap_client() {
        $soapclient = new \SoapClient(get_config('block_evasys_sync', 'evasys_wsdl_url'), [
            'trace' => 1,
            'exceptions' => 0,
            'location' => get_config('block_evasys_sync', 'evasys_soap_url')
        ]);

        $headerbody = new \SoapVar([
            new \SoapVar(get_config('block_evasys_sync', 'evasys_username'), XSD_STRING, null, null, 'Login', null),
            new \SoapVar(get_config('block_evasys_sync', 'evasys_password'), XSD_STRING, null, null, 'Password', null),
        ], SOAP_ENC_OBJECT);
        $header = new \SOAPHEADER('soap', 'Header', $headerbody);
        $soapclient->__setSoapHeaders($header);
        return $soapclient;
    }
}
