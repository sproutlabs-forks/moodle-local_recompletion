<?php

namespace local_recompletion\event;

defined('MOODLE_INTERNAL') || die();

class course_marked_complete_bulk extends \core\event\base
{

    /**
     * Init method, set basic properties for this event.
     */
    protected function init()
    {
        $this->data['crud'] = 'u'; // 'c' create, 'r' read, 'u' update, 'd' delete.
        $this->data['edulevel'] = self::LEVEL_TEACHING; // Teaching event level.
        $this->data['objecttable'] = 'course_completions';
    }

    /**
     * Return event name.
     *
     * @return string
     */
    public static function get_name()
    {
        return get_string('event_coursemarkedcompletebulk', 'local_recompletion');
    }

    /**
     * Return event description.
     *
     * @return string
     */
    public function get_description()
    {
        return "The user with id '{$this->relateduserid}' was marked complete for course with id '{$this->objectid}' via bulk recompletion CSV upload.";
    }

    /**
     * Return URL related to this event (optional).
     *
     * @return \moodle_url
     */
    public function get_url()
    {
        return new \moodle_url('/course/view.php', ['id' => $this->objectid]);
    }

    /**
     * Validation for event data.
     */
    protected function validate_data()
    {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set.');
        }
        if (!isset($this->objectid)) {
            throw new \coding_exception('The objectid (courseid) must be set.');
        }
    }
}
