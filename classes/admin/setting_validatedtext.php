<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Admin text setting with a caller-supplied validator.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_recertify\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * A text setting that validates its value with a closure.
 *
 * Core gained admin_setting::set_validate_function() partway through the 4.4 series, so
 * it cannot be relied on at this plugin's minimum supported version. Overriding
 * validate() works on every supported release.
 */
class setting_validatedtext extends \admin_setting_configtext {
    /** @var callable Returns an error string, or '' when the value is acceptable. */
    protected $validator;

    /**
     * Construct the setting.
     *
     * @param string   $name Unique ascii name.
     * @param string   $visiblename Localised name.
     * @param string   $description Localised description.
     * @param mixed    $defaultsetting Default value.
     * @param callable $validator Receives the submitted value, returns '' or an error message.
     * @param mixed    $paramtype PARAM_* constant used to clean the value.
     */
    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        $defaultsetting,
        callable $validator,
        $paramtype = PARAM_TEXT
    ) {
        $this->validator = $validator;
        parent::__construct($name, $visiblename, $description, $defaultsetting, $paramtype);
    }

    /**
     * Validate the submitted value.
     *
     * @param string $data Submitted value.
     * @return bool|string True when valid, otherwise the error message to display.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        $validator = $this->validator;
        $error = $validator((string)$data);

        return $error === '' ? true : $error;
    }
}
