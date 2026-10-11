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
 * Shared filtering logic used by both the real-time writer and the scheduled importer,
 * so both code paths apply exactly the same log_scope / role / event-name rules.
 *
 * @package     logstore_lanalytics
 * @copyright   Lehr- und Forschungsgebiet Ingenieurhydrologie - RWTH Aachen University
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace logstore_lanalytics\log;

use stdClass;
use context_course;

/**
 * Filtering rules shared by the real-time writer and the scheduled import task.
 */
class filter {
    /** @var stdClass|null cached filter settings */
    protected static $settings = null;

    /** @var int[] event name => event id cache */
    protected static $eventidcache = [];

    /**
     * Reads and caches the settings relevant for filtering log events.
     *
     * @param bool $forcereload force re-reading the settings.
     * @return stdClass
     */
    public static function get_settings(bool $forcereload = false): stdClass {
        if (self::$settings !== null && !$forcereload) {
            return self::$settings;
        }

        $courseids = [];
        $logscope = get_config('logstore_lanalytics', 'log_scope');
        if ($logscope === false) {
            $logscope = 'all';
        }

        if ($logscope === 'include' || $logscope === 'exclude') {
            $courseids = get_config('logstore_lanalytics', 'course_ids');
            if ($courseids === false || $courseids === '') {
                $courseids = [];
            } else {
                $courseids = array_map('trim', explode(',', $courseids));
            }
        }

        $trackingrolesstr = get_config('logstore_lanalytics', 'tracking_roles');
        $trackingroles = [];
        if ($trackingrolesstr !== false && $trackingrolesstr !== '') {
            $trackingroles = array_map('trim', explode(',', $trackingrolesstr));
        }

        $nontrackingrolesstr = get_config('logstore_lanalytics', 'nontracking_roles');
        $nontrackingroles = [];
        if ($nontrackingrolesstr !== false && $nontrackingrolesstr !== '') {
            $nontrackingroles = array_map('trim', explode(',', $nontrackingrolesstr));
        }

        self::$settings = (object) [
            'logscope' => $logscope,
            'courseids' => $courseids,
            'trackingroles' => $trackingroles,
            'nontrackingroles' => $nontrackingroles,
        ];

        return self::$settings;
    }

    /**
     * Determines whether an event/row for the given course and user should be tracked,
     * based on the log_scope and tracking_roles/nontracking_roles settings.
     *
     * @param int $courseid
     * @param int $userid
     * @param stdClass|null $settings settings from get_settings(), fetched automatically if omitted
     * @return bool
     */
    public static function should_track_event($courseid, $userid, ?stdClass $settings = null): bool {
        $settings = $settings ?? self::get_settings();
        $logscope = $settings->logscope;
        $courseids = $settings->courseids;
        $trackingroles = $settings->trackingroles;
        $nottrackingroles = $settings->nontrackingroles;

        // First checking the fast option.
        if (
            $logscope !== 'all'
            && (($logscope === 'include' && !in_array($courseid, $courseids))
            || ($logscope === 'exclude' && in_array($courseid, $courseids)))
        ) {
            return false;
        }
        if (count($trackingroles) !== 0 || count($nottrackingroles) !== 0) {
            $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
            $trackevent = true;
            // Context might not be defined for global events like login, main page.
            if ($coursecontext) {
                $userroles = get_user_roles($coursecontext, $userid);
                if (isguestuser($userid)) {
                    // We "fake" a guest role here as only the shortname matters (that way, we don't need another database request).
                    $guestrole = new \stdClass();
                    $guestrole->shortname = 'guest';
                    $userroles[] = $guestrole;
                }
                if (count($trackingroles) !== 0) {
                    // Whitelist mode, respecting blacklist.
                    $trackevent = false;
                    foreach ($userroles as $role) {
                        // Blacklisted.
                        if (in_array($role->shortname, $nottrackingroles)) {
                            $trackevent = false;
                            break;
                        }
                        // Whitelisted.
                        if (in_array($role->shortname, $trackingroles)) {
                            $trackevent = true;
                        }
                    }
                } else {
                    // Blacklist mode, no whitelist defined.
                    foreach ($userroles as $role) {
                        if (in_array($role->shortname, $nottrackingroles)) {
                            $trackevent = false;
                            break;
                        }
                    }
                }
            } else if (count($trackingroles) !== 0) {
                // Whitelist is active -> only track specific roles, therefore skip this one as no role is defined.
                $trackevent = false;
            }
            return $trackevent;
        }

        return true;
    }

    /**
     * Resolves an eventname to its id in logstore_lanalytics_evtname, inserting a new
     * row if the eventname hasn't been seen before. Results are cached per request.
     *
     * @param string $eventname
     * @return int
     */
    public static function resolve_eventid(string $eventname): int {
        global $DB;

        if (isset(self::$eventidcache[$eventname])) {
            return self::$eventidcache[$eventname];
        }

        $dbevent = $DB->get_record('logstore_lanalytics_evtname', ['eventname' => $eventname], 'id');
        if ($dbevent) {
            $eventid = (int) $dbevent->id;
        } else {
            $evtrecord = new stdClass();
            $evtrecord->eventname = $eventname;
            $eventid = (int) $DB->insert_record('logstore_lanalytics_evtname', $evtrecord, true);
        }

        self::$eventidcache[$eventname] = $eventid;
        return $eventid;
    }

    /**
     * Clears the in-memory caches.
     */
    public static function reset_cache(): void {
        self::$settings = null;
        self::$eventidcache = [];
    }
}
