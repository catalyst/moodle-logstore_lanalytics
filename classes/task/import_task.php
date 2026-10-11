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
 * Scheduled log import task.
 *
 * @package     logstore_lanalytics
 * @copyright   Lehr- und Forschungsgebiet Ingenieurhydrologie - RWTH Aachen University
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace logstore_lanalytics\task;

use logstore_lanalytics\log\filter;
use stdClass;

/**
 * Scheduled task importing events from the standard log into the analytics log.
 */
class import_task extends \core\task\scheduled_task {
    /** @var int default batch size */
    const DEFAULT_BATCHSIZE = 10000;

    /** @var int device value for web service requests, same as MOODLE_API in log/store.php */
    const MOODLE_API = 10100;

    /** @var int seconds a source row must have existed before import, as ids can commit out of order */
    const SAFETY_LAG = 120;

    /** @var int default time limit (seconds) */
    const DEFAULT_TIMELIMIT = 300;

    /**
     * Get a descriptive name for this task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskimport', 'logstore_lanalytics');
    }

    /**
     * Imports events from logstore_standard_log into logstore_lanalytics_log.
     *
     * Only runs when the loggingmode setting is scheduled; otherwise the real-time writer
     * handles logging and this task does nothing.
     *
     * The task resumes from the lastimportedid checkpoint. If none exists, the starting point
     * is derived from the newest event time in logstore_lanalytics_log.
     * An invalid stored checkpoint also aborts the task, as it needs manual recovery.
     * Rows are imported in batches of up to import_batchsize source rows (gaps in
     * ids are skipped) until nothing is left or import_timelimit seconds have passed.
     * Each batch is filtered with the same rules as the real-time writer and saved together with
     * its checkpoint in one transaction, so a failed run can simply be repeated.
     */
    public function execute() {
        $loggingmode = get_config('logstore_lanalytics', 'loggingmode');
        if ($loggingmode !== 'scheduled') {
            mtrace('Logging mode is not scheduled, nothing to import.');
            return;
        }

        if (!self::is_standard_log_store_enabled()) {
            mtrace('WARNING: the Standard log store (logstore_standard) does not appear to be ' .
                'enabled. Scheduled mode reads from logstore_standard_log, so no new events will be ' .
                'imported until it is enabled via Site administration > Plugins > Logging > Manage log stores.');
        }

        $batchsize = (int) get_config('logstore_lanalytics', 'import_batchsize');
        if ($batchsize <= 0) {
            $batchsize = self::DEFAULT_BATCHSIZE;
        }

        $timelimit = (int) get_config('logstore_lanalytics', 'import_timelimit');
        if ($timelimit <= 0) {
            $timelimit = self::DEFAULT_TIMELIMIT;
        }

        // A source row may have been purged without invalidating its checkpoint, as ids keep
        // increasing. Never infer validity by checking whether the checkpoint id still exists.
        $storedid = get_config('logstore_lanalytics', 'lastimportedid');
        if ($storedid === false || $storedid === '') {
            $lastid = self::derive_starting_point();
            set_config('lastimportedid', $lastid, 'logstore_lanalytics');
            mtrace("  Initial checkpoint: {$lastid}.");
        } else if (!ctype_digit((string) $storedid) || filter_var((string) $storedid, FILTER_VALIDATE_INT) === false) {
            self::fail('Invalid lastimportedid checkpoint; manual recovery required.');
        } else {
            $lastid = (int) $storedid;
        }

        filter::reset_cache();
        $start = time();
        $totalimported = 0;
        $startedat = $lastid;

        while (time() < $start + $timelimit) {
            [$newid, $imported] = self::import_batch($lastid, $batchsize);
            if ($newid === $lastid) {
                break;
            }
            mtrace("  Imported rows from id > {$lastid} to <= {$newid}");
            $lastid = $newid;
            $totalimported += $imported;
        }

        mtrace("  Import finished. Checkpoint advanced from id {$startedat} to {$lastid} " .
            "({$totalimported} rows imported this run).");
    }

    /**
     * Checks whether the core "Standard log" store (logstore_standard) is enabled, since that is
     * what populates logstore_standard_log, the sole data source for this task in scheduled mode.
     *
     * @return bool
     */
    protected static function is_standard_log_store_enabled(): bool {
        $enabled = explode(',', get_config('tool_log', 'enabled_stores') ?: '');
        return in_array('logstore_standard', $enabled);
    }

    /**
     * Imports the next batch of up to $batchsize rows after $offsetid from logstore_standard_log,
     * applying the shared filtering rules, then advances the stored checkpoint in the same
     * transaction as the data insert. If the batch fails, both are rolled back, so the next run
     * simply resumes from the previous checkpoint.
     *
     * @param int $offsetid
     * @param int $batchsize
     * @return array [new checkpoint, number of rows imported after filtering]
     */
    protected static function import_batch(int $offsetid, int $batchsize): array {
        global $DB, $CFG;

        $transaction = $DB->start_delegated_transaction();

        try {
            $sql = "SELECT *
                      FROM {logstore_standard_log}
                     WHERE id > :offsetid
                  ORDER BY id";
            $rows = $DB->get_records_sql($sql, ['offsetid' => $offsetid], 0, $batchsize);

            $settings = filter::get_settings();
            $records = [];
            $eventstotrack = [];
            $checkpoint = $offsetid;
            $cutoff = time() - self::SAFETY_LAG;
            foreach ($rows as $row) {
                // Rows are ordered by id, so stop at the first recent one and leave the rest for the next run.
                if ((int) $row->timecreated >= $cutoff) {
                    break;
                }
                $checkpoint = (int) $row->id;
                if (!filter::should_track_event((int) $row->courseid, (int) $row->userid, $settings)) {
                    continue;
                }
                $device = $row->origin === 'ws' ? self::MOODLE_API : 0;
                $event = (array) $row;
                $event['other'] = self::decode_other($row->other);
                $event['device'] = $device;
                $eventstotrack[] = $event;
                $record = new stdClass();
                $record->eventid = filter::resolve_eventid($row->eventname);
                $record->timecreated = $row->timecreated;
                $record->courseid = $row->courseid;
                $record->contextid = $row->contextid;
                $record->device = $device; // Historical rows carry no user-agent, only web service calls are known.
                $records[] = $record;
            }
            unset($rows);

            if (count($records) !== 0) {
                $DB->insert_records('logstore_lanalytics_log', $records);

                // Iterate over lalog plugins and call their logger::log function
                $pluginman = \core_plugin_manager::instance();
                $lalogplugins = $pluginman->get_present_plugins('lalog');
                if ($lalogplugins !== null) {
                    foreach ($lalogplugins as $plugin) {
                        $path = substr($plugin->component, 6);
                        include_once($CFG->dirroot. "/local/learning_analytics/logs/{$path}/classes/lalog/logger.php");
                        $loggerclass = "{$plugin->component}\\logger";
                        $loggerclass::log($eventstotrack);
                    }
                }
            }

            if ($checkpoint !== $offsetid) {
                // Persisted in the same transaction as the data above -> atomic.
                set_config('lastimportedid', $checkpoint, 'logstore_lanalytics');
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Event ids created inside the rolled back transaction no longer exist.
            filter::reset_cache();
            $transaction->rollback($e); // Rethrows; the next run re-reads the checkpoint from the database.
        }

        return [$checkpoint, count($records)];
    }

    /**
     * Decodes the "other" column of logstore_standard_log, which holds serialized PHP or JSON data.
     *
     * @param string|null $other
     * @return array|null
     */
    protected static function decode_other(?string $other): ?array {
        if ($other === null) {
            return null;
        }
        if ($other === 'N;' || preg_match('~^.:~', $other)) {
            $decoded = unserialize($other, ['allowed_classes' => [stdClass::class]]);
        } else {
            $decoded = json_decode($other, true);
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Determines the logstore_standard_log id to resume importing from when there is no stored
     * checkpoint (first run after switching to "scheduled" mode).
     *
     * Everything up to the newest event time already in logstore_lanalytics_log is assumed to be
     * covered (by real-time logging or an earlier import), so the checkpoint is the id just before
     * the first standard log row at or after that time. This also works if the plugin was
     * disabled for a long time or logstore_standard_log was purged in the meantime. Events from the
     * very last second may be imported twice; this is preferred over silently skipping some.
     *
     * @return int the logstore_standard_log id to use as the initial checkpoint (0 = import all)
     */
    protected static function derive_starting_point(): int {
        global $DB;

        $newest = $DB->get_records_sql("SELECT timecreated FROM {logstore_lanalytics_log} ORDER BY id DESC", [], 0, 1);
        if (!$newest) {
            // Fresh installations can import from the beginning.
            return 0;
        }

        $firstnewer = $DB->get_field_sql(
            "SELECT MIN(id) FROM {logstore_standard_log} WHERE timecreated >= ?",
            [(int) reset($newest)->timecreated]
        );
        if ($firstnewer !== null) {
            return (int) $firstnewer - 1;
        }

        // Nothing at or after that time in the standard log, so everything there is already covered.
        return (int) $DB->get_field_sql("SELECT MAX(id) FROM {logstore_standard_log}");
    }

    /**
     * Aborts the task with a readable message.
     *
     * @param string $message
     */
    protected static function fail(string $message): void {
        throw new \moodle_exception('generalexceptionmessage', 'error', '', $message);
    }
}
