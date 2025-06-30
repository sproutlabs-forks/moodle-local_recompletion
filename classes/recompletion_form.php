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

defined('MOODLE_INTERNAL') || die();

class local_recompletion_recompletion_form extends moodleform
{

    public function definition()
    {

        $mform = $this->_form;
        $course = $this->_customdata['course'];
        $config = get_config('local_recompletion');

        // Enable recompletion.
        $mform->addElement('checkbox', 'enable', get_string('enablerecompletion', 'local_recompletion'));
        $mform->addHelpButton('enable', 'enablerecompletion', 'local_recompletion');

        // Recompletion duration.
        $options = array('optional' => false, 'defaultunit' => 86400);
        $mform->addElement('duration', 'recompletionduration', get_string('recompletionrange', 'local_recompletion'), $options);
        $mform->addHelpButton('recompletionduration', 'recompletionrange', 'local_recompletion');
        $mform->disabledIf('recompletionduration', 'enable', 'notchecked');
        $mform->setDefault('recompletionduration', $config->duration);

        // Email enable.
        $mform->addElement('checkbox', 'recompletionemailenable', get_string('recompletionemailenable', 'local_recompletion'));
        $mform->setDefault('recompletionemailenable', $config->emailenable);
        $mform->addHelpButton('recompletionemailenable', 'recompletionemailenable', 'local_recompletion');
        $mform->disabledIf('recompletionemailenable', 'enable', 'notchecked');

        // Email Notification settings.
        $mform->addElement('header', 'emailheader', get_string('emailrecompletiontitle', 'local_recompletion'));
        $mform->setExpanded('emailheader', false);
        $mform->addElement('text', 'recompletionemailsubject', get_string('recompletionemailsubject', 'local_recompletion'), 'size="80"');
        $mform->setType('recompletionemailsubject', PARAM_TEXT);
        $mform->addHelpButton('recompletionemailsubject', 'recompletionemailsubject', 'local_recompletion');
        $mform->disabledIf('recompletionemailsubject', 'enable', 'notchecked');
        $mform->disabledIf('recompletionemailsubject', 'recompletionemailenable', 'notchecked');
        $mform->setDefault('recompletionemailsubject', $config->emailsubject);

        $options = array('cols' => '60', 'rows' => '8');
        $mform->addElement('textarea', 'recompletionemailbody', get_string('recompletionemailbody', 'local_recompletion'), $options);
        $mform->addHelpButton('recompletionemailbody', 'recompletionemailbody', 'local_recompletion');
        $mform->disabledIf('recompletionemailbody', 'enable', 'notchecked');
        $mform->disabledIf('recompletionemailbody', 'recompletionemailenable', 'notchecked');
        $mform->setDefault('recompletionemailbody', $config->emailbody);

        // Advanced recompletion settings.
        $mform->addElement('header', 'advancedheader', get_string('advancedrecompletiontitle', 'local_recompletion'));
        $mform->setExpanded('advancedheader', false);

        $mform->addElement('checkbox', 'deletegradedata', get_string('deletegradedata', 'local_recompletion'));
        $mform->setDefault('deletegradedata', $config->deletegradedata);
        $mform->addHelpButton('deletegradedata', 'deletegradedata', 'local_recompletion');

        $mform->addElement('checkbox', 'archivecompletiondata', get_string('archivecompletiondata', 'local_recompletion'));
        $archivedefault = $config->forcearchivecompletiondata ? 1 : $config->archivecompletiondata;
        $mform->setDefault('archivecompletiondata', $archivedefault);
        $mform->addHelpButton('archivecompletiondata', 'archivecompletiondata', 'local_recompletion');

        // Supported activities.
        $activities = local_recompletion_get_supported_activities();
        foreach ($activities as $activity) {
            $fqn = 'local_recompletion\\activities\\' . $activity;
            $fqn::editingform($mform);
        }

        $mform->disabledIf('deletegradedata', 'enable', 'notchecked');
        $mform->disabledIf('archivecompletiondata', 'enable', 'notchecked');
        $mform->disabledIf('archivecompletiondata', 'forcearchive', 'eq');

        // Advance Notification Settings.
        $mform->addElement('header', 'notifyheader', get_string('notifyheading', 'local_recompletion'));
        $mform->setExpanded('notifyheader', false);

        $mform->addElement('text', 'notifyleadtime', get_string('defaultleadtime', 'local_recompletion'), 'size="5"');
        $mform->setType('notifyleadtime', PARAM_INT);
        $mform->setDefault('notifyleadtime', $config->notifyleadtime);
       

        $mform->addElement('text', 'notifysubject', get_string('defaultnotifysubject', 'local_recompletion'), 'size="80"');
        $mform->setType('notifysubject', PARAM_TEXT);
        $mform->setDefault('notifysubject', $config->notifysubject);
        

        $options = array('cols' => '60', 'rows' => '8');
        $mform->addElement('textarea', 'notifymessagebody', get_string('recompletionemailbody', 'local_recompletion'), $options);
        $mform->addHelpButton('notifymessagebody', 'recompletionemailbody', 'local_recompletion');
        $mform->disabledIf('notifymessagebody', 'enable', 'notchecked');
        $mform->disabledIf('notifymessagebody', 'recompletionemailenable', 'notchecked');
        $mform->setDefault('notifymessagebody', $config->notifymessagebody);

        // Buttons and hidden fields.
        $this->add_action_buttons();
        $mform->addElement('hidden', 'course', $course->id);
        $mform->setType('course', PARAM_INT);
        $mform->addElement('hidden', 'forcearchive', $config->forcearchivecompletiondata);
        $mform->setType('forcearchive', PARAM_BOOL);
    }
}
