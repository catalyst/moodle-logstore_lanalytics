# Logstore Learning Analytics for Moodle

This plugin implements a logstore to log data used for analytics purposes. It is designed to work together with [`local_learning_analytics`](https://github.com/rwthanalytics/moodle-local_learning_analytics). To keep documentation in one place, you find all documentation in that repository.

Plugins:

- [`local_learning_analytics`](https://github.com/rwthanalytics/moodle-local_learning_analytics): User Interface
- [`logstore_lanalytics`](https://github.com/rwthanalytics/moodle-logstore_lanalytics): Logs the events to the database (this plugin)




## Scheduled logging mode

By default events are logged in real time. Setting `loggingmode` to Scheduled makes a task import the events from `logstore_standard_log` instead (the standard log store must stay enabled).

**Switching back from scheduled to real time:** the import task does nothing in real-time mode, so events logged between the last import and the switch are not imported. Run the task manually right before switching, so nothing is lost:

```
php admin/cli/scheduled_task.php --execute='\logstore_lanalytics\task\import_task'
```

Switching to scheduled mode later continues from the newest event already stored.
