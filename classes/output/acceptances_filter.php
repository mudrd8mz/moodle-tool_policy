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
 * Provides {@link tool_policy\output\acceptances_filter} class.
 *
 * @package     tool_policy
 * @category    output
 * @copyright   2018 Marina Glancy
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_policy\output;


use tool_policy\api;
use tool_policy\policy_version;

class acceptances_filter implements \templatable, \renderable {

    /** @var array $filtersapplied The list of selected filter options. */
    protected $filtersapplied;

    /** @var string $searchstring */
    protected $searchstrings;

    /** @var array list of available versions */
    protected $versions = null;

    /** @var array list of available roles for the filter */
    protected $roles;

    const FILTER_SEARCH_STRING = 0;
    const FILTER_POLICYID = 1;
    const FILTER_VERSIONID = 2;
    const FILTER_CAPABILITY_ACCEPT = 3;
    const FILTER_STATUS = 4;
    const FILTER_ROLE = 5;

    /**
     * Constructor.
     *
     * @param array $policyid Specified policy id
     * @param array $versionid Specified version id
     * @param array $filtersapplied The list of selected filter option values.
     */
    public function __construct($policyid, $versionid, $filtersapplied) {
        $this->filtersapplied = [];
        $this->roles = get_assignable_roles(\context_system::instance());
        if ($policyid) {
            $this->add_filter(self::FILTER_POLICYID, $policyid);
        }
        if ($versionid) {
            $this->add_filter(self::FILTER_VERSIONID, $versionid);
        }
        foreach ($filtersapplied as $filter) {
            if (preg_match('/^([1-9]\d*):(\d+)$/', $filter, $parts)) {
                // This is a pre-set filter (policy, version, status, etc.).
                $allowmultiple = false;
                switch ((int)$parts[1]) {
                    case self::FILTER_POLICYID:
                    case self::FILTER_VERSIONID:
                        $value = (int)$parts[2];
                        break;
                    case self::FILTER_CAPABILITY_ACCEPT:
                    case self::FILTER_STATUS:
                        $value = (int)(bool)$parts[2];
                        break;
                    case self::FILTER_ROLE:
                        $value = (int)$parts[2];
                        if (!array_key_exists($value, $this->roles)) {
                            continue 2;
                        }
                        $allowmultiple = true;
                        break;
                    default:
                        // Unrecognised filter.
                        continue 2;
                }

                $this->add_filter((int)$parts[1], $value, $allowmultiple);
            } else if (trim($filter) !== '') {
                // This is a search string.
                $this->add_filter(self::FILTER_SEARCH_STRING, trim($filter), true);
            }
        }
    }

    /**
     * Adds an applied filter
     *
     * @param mixed $key
     * @param mixed $value
     * @param bool $allowmultiple
     */
    protected function add_filter($key, $value, $allowmultiple = false) {
        if ($allowmultiple || empty($this->get_filter_values($key))) {
            $this->filtersapplied[] = [$key, $value];
        }
    }

    /**
     * Is there a filter by policy
     *
     * @return null|int null if there is no filter, otherwise the policy id
     */
    public function get_policy_id_filter() {
        return $this->get_filter_value(self::FILTER_POLICYID);
    }

    /**
     * Is there a filter by version
     *
     * @return null|int null if there is no filter, otherwise the version id
     */
    public function get_version_id_filter() {
        return $this->get_filter_value(self::FILTER_VERSIONID);
    }

    /**
     * Are there filters by search strings
     *
     * @return string[] array of string filters
     */
    public function get_search_strings() {
        return $this->get_filter_values(self::FILTER_SEARCH_STRING);
    }

    /**
     * Is there a filter by status (agreed/not agreed).
     *
     * @return null|0|1 null if there is no filter, 0/1 if there is a filter by status
     */
    public function get_status_filter() {
        return $this->get_filter_value(self::FILTER_STATUS);
    }

    /**
     * Are there filters by role
     *
     * @return array list of role ids
     */
    public function get_role_filters() {
        return $this->get_filter_values(self::FILTER_ROLE);
    }

    /**
     * Is there a filter by capability (can accept/cannot accept).
     *
     * @return null|0|1 null if there is no filter, 0/1 if there is a filter by capability
     */
    public function get_capability_accept_filter() {
        return $this->get_filter_value(self::FILTER_CAPABILITY_ACCEPT);
    }

    /**
     * Get all values of the applied filter
     *
     * @param string $filtername
     * @return array
     */
    protected function get_filter_values($filtername) {
        $values = [];
        foreach ($this->filtersapplied as $filter) {
            if ($filter[0] == $filtername) {
                $values[] = $filter[1];
            }
        }
        return $values;
    }

    /**
     * Get one value of the applied filter
     *
     * @param string $filtername
     * @return mixed
     */
    protected function get_filter_value($filtername, $default = null) {
        if ($values = $this->get_filter_values($filtername)) {
            $value = reset($values);
            return $value;
        }
        return $default;
    }

    /**
     * List of policies that match current filters
     *
     * @return array|null|\stdClass
     */
    public function get_versions() {
        if ($this->versions === null) {
            $policyid = $this->get_policy_id_filter();
            $versionid = $this->get_version_id_filter();
            $validateversion = function($version) use ($versionid) {
                return (!$versionid || ($versionid == $version->id)) && $version->audience != policy_version::AUDIENCE_GUESTS;
            };
            // Find versions that are not guest-only and match policyid and versionid filter.
            // When versionid is not specified only look at current versions. Always ignore drafts.
            $this->versions = [];
            $policies = \tool_policy\api::list_policies($policyid ? [$policyid] : null);
            foreach ($policies as $policy) {
                if ($policy->currentversion && $validateversion($policy->currentversion)) {
                    $this->versions[] = $policy->currentversion;
                }

                if ($versionid) {
                    if ($versions = array_filter($policy->archivedversions, $validateversion)) {
                        $version = reset($versions);
                        $this->versions[] = $version;
                    }
                }
            }
        }
        return $this->versions;
    }

    /**
     * If policyid or versionid is specified return one single policy that needs to be shown
     *
     * If neither policyid nor versionid is specified this method returns null.
     *
     * @return mixed|null
     */
    public function get_single_version() {
        if ($this->get_version_id_filter() || $this->get_policy_id_filter()) {
            $versions = $this->get_versions();
            return reset($versions);
        }
        return null;
    }

    /**
     * Returns URL of the acceptances page with all current filters applied
     *
     * @return \moodle_url
     */
    public function get_url() {
        $urlparams = [];
        if ($policyid = $this->get_policy_id_filter()) {
            $urlparams['policyid'] = $policyid;
        }
        if ($versionid = $this->get_version_id_filter()) {
            $urlparams['versionid'] = $versionid;
        }
        $i = 0;
        foreach ($this->filtersapplied as $filter) {
            if ($filter[0] != self::FILTER_POLICYID && $filter[0] != self::FILTER_VERSIONID) {
                if ($filter[0] == self::FILTER_SEARCH_STRING) {
                    $urlparams['unified-filters['.($i++).']'] = $filter[1];
                } else {
                    $urlparams['unified-filters['.($i++).']'] = join(':', $filter);
                }
            }
        }
        return new \moodle_url('/admin/tool/policy/acceptances.php', $urlparams);
    }

    /**
     * Creates an option name for the smart select for the version
     *
     * @param \stdClass $version
     * @return string
     */
    protected function get_version_option_for_filter($version) {
        if ($version->status == policy_version::STATUS_ACTIVE) {
            $a = (object)['name' => format_string($version->revision), 'status' => get_string('status'.policy_version::STATUS_ACTIVE, 'tool_policy')];
            return get_string('filterrevisionstatus', 'tool_policy', $a);
        } else {
            return get_string('filterrevision', 'tool_policy', format_string($version->revision));
        }
    }

    /**
     * Build list of filters available for this page
     *
     * @return array [$availablefilters, $selectedoptions]
     */
    protected function build_available_filters() {
        $selectedoptions = [];
        $availablefilters = [];

        // Policies.
        if ($singleversion = $this->get_single_version()) {
            $selectedoptions[] = $key = self::FILTER_POLICYID . ':' . $singleversion->policyid;
            $availablefilters[$key] = get_string('filterpolicy', 'tool_policy', format_string($singleversion->name));
            $key = self::FILTER_VERSIONID . ':' . $singleversion->id;
            $availablefilters[$key] = $this->get_version_option_for_filter($singleversion);
            if ($this->get_version_id_filter()) {
                $selectedoptions[] = $key;
            }
        } else {
            $policies = api::list_policies();
            foreach ($policies as $policy) {
                if ($policy->currentversion && $policy->currentversion->audience != policy_version::AUDIENCE_GUESTS) {
                    $key = self::FILTER_POLICYID . ':' . $policy->id;
                    $availablefilters[$key] = get_string('filterpolicy', 'tool_policy', format_string($policy->currentversion->name));
                }
            }
        }

        // Permissions.
        $permissions = [
            self::FILTER_CAPABILITY_ACCEPT . ':1' => get_string('filtercapabilityyes', 'tool_policy'),
            self::FILTER_CAPABILITY_ACCEPT . ':0' => get_string('filtercapabilityno', 'tool_policy'),
        ];
        if (($currentpermission = $this->get_capability_accept_filter()) !== null) {
            $selectedoptions[] = $key = self::FILTER_CAPABILITY_ACCEPT . ':' . $currentpermission;
            $permissions = array_intersect_key($permissions, [$key => true]);
        }
        $availablefilters += $permissions;

        // Status.
        $statuses = [
            self::FILTER_STATUS.':1' => get_string('filterstatusyes', 'tool_policy'),
            self::FILTER_STATUS.':0' => get_string('filterstatusno', 'tool_policy'),
        ];
        if (($currentstatus = $this->get_status_filter()) !== null) {
            $selectedoptions[] = $key = self::FILTER_STATUS . ':' . $currentstatus;
            $statuses = array_intersect_key($statuses, [$key => true]);
        }
        $availablefilters += $statuses;

        // Roles.
        $currentroles = $this->get_role_filters();
        foreach ($this->roles as $roleid => $rolename) {
            $key = self::FILTER_ROLE . ':' . $roleid;
            $availablefilters[$key] = get_string('filterrole', 'tool_policy', $rolename);
            if (in_array($roleid, $currentroles)) {
                $selectedoptions[] = $key;
            }
        }

        // Search string.
        foreach ($this->get_search_strings() as $str) {
            $selectedoptions[] = $str;
            $availablefilters[$str] = $str;
        }

        return [$availablefilters, $selectedoptions];
    }

    /**
     * Function to export the renderer data in a format that is suitable for a mustache template.
     *
     * @param renderer_base $output Used to do a final render of any components that need to be rendered for export.
     * @return \stdClass|array
     */
    public function export_for_template(\renderer_base $output) {
        $data = new \stdClass();
        $data->action = (new \moodle_url('/admin/tool/policy/acceptances.php'))->out(false);

        $data->filteroptions = [];
        $originalfilteroptions = [];
        list($avilablefilters, $selectedoptions) = $this->build_available_filters();
        foreach ($avilablefilters as $value => $label) {
            $selected = in_array($value, $selectedoptions);
            $filteroption = (object)[
                'value' => $value,
                'label' => $label
            ];
            $originalfilteroptions[] = $filteroption;
            $filteroption->selected = $selected;
            $data->filteroptions[] = $filteroption;
        }
        $data->originaloptionsjson = json_encode($originalfilteroptions);
        return $data;
    }
}