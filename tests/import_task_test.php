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
 * Tests for the scheduled import task (\logstore_lanalytics\task\import_task).
 *
 * @package     logstore_lanalytics
 * @copyright   Lehr- und Forschungsgebiet Ingenieurhydrologie - RWTH Aachen University
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace logstore_lanalytics;

use logstore_lanalytics\task\import_task;
use logstore_lanalytics\log\filter;
use ReflectionMethod;

/**
 * Tests for the scheduled log import task.
 *
 * @covers \logstore_lanalytics\task\import_task
 */
final class import_task_test extends \advanced_testcase {
    /**
     * Prepares the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        filter::reset_cache();
        $this->setOutputCallback(function () {
            // Swallow the task's mtrace() output.
        });

        // Sensible defaults for filtering, so tests are unaffected unless overridden.
        set_config('log_scope', 'all', 'logstore_lanalytics');
        set_config('course_ids', '', 'logstore_lanalytics');
        set_config('tracking_roles', '', 'logstore_lanalytics');
        set_config('nontracking_roles', '', 'logstore_lanalytics');

        // The task warns (but still runs) if this isn't enabled - keep it quiet/realistic.
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
    }

    /**
     * Inserts a raw row directly into {logstore_standard_log}, bypassing the event system so
     * tests have full, deterministic control over courseid/contextid/timecreated/eventname.
     */
    protected function create_standard_log_row(array $overrides = []): int {
        global $DB;

        $record = (object) array_merge([
            'eventname' => '\\logstore_lanalytics\\event\\unittest_executed',
            'component' => 'logstore_lanalytics',
            'action' => 'viewed',
            'target' => 'course',
            'crud' => 'r',
            'edulevel' => 2,
            'contextid' => 1,
            'contextlevel' => 50,
            'contextinstanceid' => 0,
            'userid' => 0,
            'courseid' => 0,
            'anonymous' => 0,
            'timecreated' => time() - 3600,
        ], $overrides);

        return (int) $DB->insert_record('logstore_standard_log', $record);
    }

    /**
     * Invokes a protected/private static method via reflection.
     */
    protected function call_static(string $method, array $args = []) {
        $ref = new ReflectionMethod(import_task::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    public function test_execute_is_noop_when_not_scheduled(): void {
        global $DB;

        set_config('loggingmode', 'realtime', 'logstore_lanalytics');
        $this->create_standard_log_row(['courseid' => 5, 'timecreated' => time()]);

        $task = new import_task();
        $task->execute();

        $this->assertCount(0, $DB->get_records('logstore_lanalytics_log'));
        $this->assertFalse(get_config('logstore_lanalytics', 'lastimportedid'));
    }

    public function test_execute_imports_rows_and_advances_checkpoint(): void {
        global $DB;

        set_config('loggingmode', 'scheduled', 'logstore_lanalytics');

        $id1 = $this->create_standard_log_row(['courseid' => 5, 'contextid' => 100, 'timecreated' => 1000]);
        $id2 = $this->create_standard_log_row(['courseid' => 6, 'contextid' => 101, 'timecreated' => 1001]);

        $task = new import_task();
        $task->execute();

        $logs = $DB->get_records('logstore_lanalytics_log', [], 'id ASC');
        $this->assertCount(2, $logs);

        $checkpoint = get_config('logstore_lanalytics', 'lastimportedid');
        $this->assertEquals($id2, $checkpoint);
    }

    public function test_execute_respects_log_scope_filter(): void {
        global $DB;

        set_config('loggingmode', 'scheduled', 'logstore_lanalytics');
        set_config('log_scope', 'include', 'logstore_lanalytics');
        set_config('course_ids', '5', 'logstore_lanalytics');

        $this->create_standard_log_row(['courseid' => 5, 'contextid' => 100, 'timecreated' => 1000]);
        $this->create_standard_log_row(['courseid' => 6, 'contextid' => 101, 'timecreated' => 1001]);

        $task = new import_task();
        $task->execute();

        $logs = $DB->get_records('logstore_lanalytics_log');
        $this->assertCount(1, $logs);
        $this->assertEquals(5, reset($logs)->courseid);
    }

    public function test_execute_resumes_from_checkpoint_without_duplicating(): void {
        global $DB;

        set_config('loggingmode', 'scheduled', 'logstore_lanalytics');

        $this->create_standard_log_row(['courseid' => 5, 'contextid' => 100, 'timecreated' => 1000]);

        $task = new import_task();
        $task->execute();
        $this->assertCount(1, $DB->get_records('logstore_lanalytics_log'));

        // Running again immediately with no new rows must not duplicate anything.
        $task->execute();
        $this->assertCount(1, $DB->get_records('logstore_lanalytics_log'));

        // A newly added row must be picked up on the next run, and only that one.
        $this->create_standard_log_row(['courseid' => 5, 'contextid' => 102, 'timecreated' => 2000]);
        $task->execute();
        $this->assertCount(2, $DB->get_records('logstore_lanalytics_log'));
    }

    public function test_execute_fails_on_invalid_checkpoint(): void {
        set_config('loggingmode', 'scheduled', 'logstore_lanalytics');
        set_config('lastimportedid', 'abc', 'logstore_lanalytics');

        $this->expectException(\moodle_exception::class);
        (new import_task())->execute();
    }

    public function test_execute_does_not_regress_checkpoint_beyond_newest_row(): void {
        global $DB;

        set_config('loggingmode', 'scheduled', 'logstore_lanalytics');

        // Checkpoint is higher than any existing row, as after a purge of the newest rows.
        $this->create_standard_log_row(['courseid' => 5, 'contextid' => 100, 'timecreated' => 1000]);
        $high = $DB->get_field_sql('SELECT MAX(id) FROM {logstore_standard_log}') + 1000;
        set_config('lastimportedid', $high, 'logstore_lanalytics');

        (new import_task())->execute();

        $this->assertCount(0, $DB->get_records('logstore_lanalytics_log'));
        $this->assertEquals($high, get_config('logstore_lanalytics', 'lastimportedid'));
    }

    public function test_derive_starting_point_uses_first_row_at_or_after_local_data(): void {
        global $DB;

        $eventid = filter::resolve_eventid('\\logstore_lanalytics\\event\\unittest_executed');
        $DB->insert_record('logstore_lanalytics_log', (object) [
            'eventid' => $eventid, 'timecreated' => 5000, 'courseid' => 42, 'contextid' => 999, 'device' => 0,
        ]);

        // The plugin was disabled after time 5000: older rows must not be imported again.
        $lastold = $this->create_standard_log_row(['timecreated' => 4000]);
        $this->create_standard_log_row(['timecreated' => 6000]);

        $this->assertEquals($lastold, $this->call_static('derive_starting_point'));
    }

    public function test_derive_starting_point_uses_newest_id_when_nothing_is_newer(): void {
        global $DB;

        $eventid = filter::resolve_eventid('\\logstore_lanalytics\\event\\unittest_executed');
        $DB->insert_record('logstore_lanalytics_log', (object) [
            'eventid' => $eventid, 'timecreated' => 5000, 'courseid' => 42, 'contextid' => 999, 'device' => 0,
        ]);
        $newest = $this->create_standard_log_row(['timecreated' => 4000]);

        $this->assertEquals($newest, $this->call_static('derive_starting_point'));
    }

    public function test_derive_starting_point_reimports_rows_of_the_last_local_second(): void {
        global $DB;

        $eventid = filter::resolve_eventid('\\logstore_lanalytics\\event\\unittest_executed');
        $DB->insert_record('logstore_lanalytics_log', (object) [
            'eventid' => $eventid, 'timecreated' => 5000, 'courseid' => 42, 'contextid' => 999, 'device' => 0,
        ]);

        // Rows sharing the newest local timestamp may only be partly stored, so they are not skipped.
        $lastolder = $this->create_standard_log_row(['timecreated' => 4000]);
        $this->create_standard_log_row(['timecreated' => 5000]);
        $this->create_standard_log_row(['timecreated' => 5000]);
        $this->create_standard_log_row(['timecreated' => 6000]);

        $this->assertEquals($lastolder, $this->call_static('derive_starting_point'));
    }

    public function test_failed_batch_is_rolled_back_and_keeps_checkpoint(): void {
        global $DB;

        $this->preventResetByRollback();

        $firstid = $this->create_standard_log_row(['courseid' => 5, 'contextid' => 100, 'timecreated' => 1000]);
        $this->create_standard_log_row([
            'courseid' => 5, 'contextid' => 101, 'timecreated' => 1001, 'eventname' => 'poisoned_event',
        ]);

        [$checkpoint] = $this->call_static('import_batch', [0, 1]);
        $this->assertEquals($firstid, $checkpoint);

        // A non-numeric cached event id makes resolving the second row's event name throw.
        $cache = new \ReflectionProperty(filter::class, 'eventidcache');
        $cache->setAccessible(true);
        $cache->setValue(null, ['poisoned_event' => 'not-an-id']);

        try {
            $this->call_static('import_batch', [$checkpoint, 1]);
            $this->fail('The failing batch should throw.');
        } catch (\TypeError $e) {
            $this->assertInstanceOf(\TypeError::class, $e);
        }

        // The failed batch left no data and did not move the stored checkpoint.
        $this->assertCount(1, $DB->get_records('logstore_lanalytics_log'));
        $stored = $DB->get_field('config_plugins', 'value', ['plugin' => 'logstore_lanalytics', 'name' => 'lastimportedid']);
        $this->assertEquals($firstid, $stored);
    }

    public function test_execute_leaves_recent_rows_for_the_next_run(): void {
        global $DB;

        set_config('loggingmode', 'scheduled', 'logstore_lanalytics');
        $oldid = $this->create_standard_log_row(['courseid' => 5, 'contextid' => 100, 'timecreated' => 1000]);
        $this->create_standard_log_row(['courseid' => 5, 'contextid' => 101, 'timecreated' => time()]);

        (new import_task())->execute();

        $this->assertCount(1, $DB->get_records('logstore_lanalytics_log'));
        $this->assertEquals($oldid, get_config('logstore_lanalytics', 'lastimportedid'));
    }

    public function test_switching_modes_does_not_duplicate_imports(): void {
        global $DB;

        set_config('loggingmode', 'scheduled', 'logstore_lanalytics');
        $this->create_standard_log_row(['courseid' => 5, 'contextid' => 100, 'timecreated' => 1000]);
        (new import_task())->execute();
        $this->assertCount(1, $DB->get_records('logstore_lanalytics_log'));

        // In realtime mode the task does nothing. Changing the mode in the settings clears the checkpoint.
        set_config('loggingmode', 'realtime', 'logstore_lanalytics');
        unset_config('lastimportedid', 'logstore_lanalytics');
        $this->create_standard_log_row(['courseid' => 5, 'contextid' => 101, 'timecreated' => 2000]);
        (new import_task())->execute();
        $this->assertCount(1, $DB->get_records('logstore_lanalytics_log'));

        // Back in scheduled mode the starting point is derived from the newest stored event.
        // The starting point is the first row at or after the newest stored event, here the first row.
        set_config('loggingmode', 'scheduled', 'logstore_lanalytics');
        (new import_task())->execute();
        $this->assertCount(3, $DB->get_records('logstore_lanalytics_log'));
    }

    public function test_derive_starting_point_returns_zero_when_no_local_data(): void {
        $derived = $this->call_static('derive_starting_point');
        $this->assertEquals(0, $derived);
    }

    public function test_execute_derives_checkpoint_on_first_run_after_switching_modes(): void {
        global $DB;

        // Simulate real-time logging having already produced a row previously.
        $evtname = '\\logstore_lanalytics\\event\\unittest_executed';
        $eventid = filter::resolve_eventid($evtname);
        $DB->insert_record('logstore_lanalytics_log', (object) [
            'eventid' => $eventid, 'timecreated' => 1000, 'courseid' => 5, 'contextid' => 100, 'device' => 0,
        ]);
        $matchedid = $this->create_standard_log_row(['courseid' => 5, 'contextid' => 100, 'timecreated' => 1000, 'eventname' => $evtname]);
        // A new row that arrived after the switch to scheduled mode - only this one should import.
        $newid = $this->create_standard_log_row(['courseid' => 5, 'contextid' => 101, 'timecreated' => 2000, 'eventname' => $evtname]);

        set_config('loggingmode', 'scheduled', 'logstore_lanalytics');
        $this->assertFalse(get_config('logstore_lanalytics', 'lastimportedid'));

        $task = new import_task();
        $task->execute();

        // Rows from the newest local second are imported again (never skipped), plus the new row.
        $logs = $DB->get_records('logstore_lanalytics_log', [], 'id ASC');
        $this->assertCount(3, $logs); // Pre-existing row + same-second row again + the new one.
        $this->assertEquals($newid, get_config('logstore_lanalytics', 'lastimportedid'));
    }

    public function test_execute_imports_across_multiple_batches_without_duplicates(): void {
        global $DB;

        set_config('loggingmode', 'scheduled', 'logstore_lanalytics');
        set_config('import_batchsize', 1, 'logstore_lanalytics');

        $this->create_standard_log_row(['courseid' => 5, 'contextid' => 100, 'timecreated' => 1000]);
        $this->create_standard_log_row(['courseid' => 5, 'contextid' => 101, 'timecreated' => 1001]);
        $lastid = $this->create_standard_log_row(['courseid' => 5, 'contextid' => 102, 'timecreated' => 1002]);

        $task = new import_task();
        $task->execute();

        $this->assertCount(3, $DB->get_records('logstore_lanalytics_log'));
        $this->assertEquals($lastid, get_config('logstore_lanalytics', 'lastimportedid'));

        // A second run must not import anything again.
        $task->execute();
        $this->assertCount(3, $DB->get_records('logstore_lanalytics_log'));
    }

    public function test_execute_checkpoint_never_exceeds_newest_row_id(): void {
        set_config('loggingmode', 'scheduled', 'logstore_lanalytics');
        set_config('import_batchsize', 10000, 'logstore_lanalytics');

        $lastid = $this->create_standard_log_row(['courseid' => 5, 'contextid' => 100, 'timecreated' => 1000]);

        $task = new import_task();
        $task->execute();

        $this->assertEquals($lastid, get_config('logstore_lanalytics', 'lastimportedid'));

        // A row written after the run must still be picked up by the next run.
        $newid = $this->create_standard_log_row(['courseid' => 5, 'contextid' => 101, 'timecreated' => 1001]);
        $task->execute();

        $this->assertEquals($newid, get_config('logstore_lanalytics', 'lastimportedid'));
    }
}
