#!/usr/bin/env php
<?php

/**
 * Observium
 *
 *   This file is part of Observium.
 *
 * @package    observium
 * @subpackage poller
 * @author     Adam Armstrong <adama@memetic.org>
 * @copyright  (C) 2006-2015 Adam Armstrong
 *
 */

$client = new GearmanClient();
$client->addServer();

chdir(dirname($argv[0]));

include_once("includes/defaults.inc.php");
include_once("config.php");

// Get options before definitions!
$options = getopt("h:i:m:n:dqrV");

include_once("includes/definitions.inc.php");
include("includes/functions.inc.php");
include("includes/polling/functions.inc.php");
include_once("pollerWorker.php");

$scriptname = basename($argv[0]);

$cli = TRUE;

$poller_start = utime();

if (isset($options['V']))
{
  print_message(OBSERVIUM_PRODUCT." ".OBSERVIUM_VERSION);
  if (is_array($options['V'])) { print_versions(); }
  exit;
}
if (!isset($options['q']))
{
  print_message("%g".OBSERVIUM_PRODUCT." ".OBSERVIUM_VERSION."\n%WPoller%n\n", 'color');
  if (OBS_DEBUG) { print_versions(); }
}

if ($options['h'] == "odd")      { $options['n'] = "1"; $options['i'] = "2"; }
elseif ($options['h'] == "even") { $options['n'] = "0"; $options['i'] = "2"; }
elseif ($options['h'] == "all")  { $where = " "; $doing = "all"; }
elseif ($options['h'])
{
  $params = array();
  if (is_numeric($options['h']))
  {
    $where = "AND `device_id` = ?";
    $doing = $options['h'];
    $params[] = $options['h'];
  }
  else
  {
    $where = "AND `hostname` LIKE ?";
    $doing = $options['h'];
    $params[] = str_replace('*','%', $options['h']);
  }
}

if (isset($options['i']) && $options['i'] && isset($options['n']))
{
  $where = true; // FIXME

  $query = 'SELECT `device_id` FROM (SELECT @rownum :=0) r,
              (
                SELECT @rownum := @rownum +1 AS rownum, `device_id`
                FROM `devices`
                WHERE `disabled` = 0
                ORDER BY `device_id` ASC
              ) temp
            WHERE MOD(temp.rownum, '.$options['i'].') = ?;';
  $doing = $options['n'] ."/".$options['i'];
  $params[] = $options['n'];
}

if (!$where)
{
  print_message("%n
USAGE:
$scriptname [-drqV] [-i instances] [-n number] [-m module] [-h device]

EXAMPLE:
-h <device id> | <device hostname wildcard>  Poll single device
-h odd                                       Poll odd numbered devices  (same as -i 2 -n 0)
-h even                                      Poll even numbered devices (same as -i 2 -n 1)
-h all                                       Poll all devices
-h new                                       Poll all devices that have not had a discovery run before

-i <instances> -n <number>                   Poll as instance <number> of <instances>
                                             Instances start at 0. 0-3 for -n 4

OPTIONS:
 -h                                          Device hostname, id or key odd/even/all/new.
 -i                                          Poll instance.
 -n                                          Poll number.
 -q                                          Quiet output.
 -V                                          Show version and exit.

DEBUGGING OPTIONS:
 -r                                          Do not create or update RRDs
 -d                                          Enable debugging output.
 -dd                                         More verbose debugging output.
 -m                                          Specify module(s) (separated by commas) to be run.

%rInvalid arguments!%n", 'color', FALSE);
  exit;
}

if (isset($options['r']))
{
  $config['norrd'] = TRUE;
}

//Callback to output in the logfile (avoid opening it for each thread)
$client->setCompleteCallback(function(GearmanTask $task, $context) use (&$doing,&$polled_devices,&$poller_start)
{
    switch($context) {
        case 'poll_device_task':
                $polled_devices++;
                $poller_time = $task->data();
                $string = $argv[0] . ": $doing - device $polled_devices polled in $poller_time secs";
                print_debug($string);
                logfile($string);
            break;

  }
});


echo("Starting polling run:\n\n");
$polled_devices = 0;
if (!isset($query))
{
  $query = "SELECT `device_id` FROM `devices` WHERE `disabled` = 0 $where ORDER BY `device_id` ASC";
}

//For each row return by the query above
//Get the data for the device
//And queue a polling task
$submit_start=utime();
foreach (dbFetch($query, $params) as $device)
{
  $device = dbFetchRow("SELECT * FROM `devices` WHERE `device_id` = ?", array($device['device_id']));
  $taskWork=array();
  $taskWork[0]=$device;
  $taskWork[1]=$options;
  $taskWork[2]=$doing;
  $client->addTask('poll_device_task', serialize($taskWork), 'poll_device_task');
}
echo("Submit time : ".substr(utime()-$submit_start,0,5)." secondes\n");

//Run and wait for all task to finish
$client->runTasks();

$poller_run=utime() - $poller_start;
$poller_time=substr($poller_run, 0, 5);

echo("It took :". $poller_time . " seconds to poll : $polled_devices devices \n");
print_debug("It took :". $poller_time . " seconds to poll : $polled_devices devices \n");
logfile("It took :". $poller_time . " seconds to poll : $polled_devices devices \n");

if (!isset($options['q']))
{
  if ($config['snmp']['hide_auth'])
  {
    print_debug("NOTE, \$config['snmp']['hide_auth'] sets as TRUE, snmp community and snmp v3 auth hidden from debug output.");
  }
  print_message('Memory usage: '.formatStorage(memory_get_usage(TRUE), 2, 4).' (peak: '.formatStorage(memory_get_peak_usage(TRUE), 2, 4).')');
  print_message('MySQL: Cell['.($db_stats['fetchcell']+0).'/'.round($db_stats['fetchcell_sec']+0,2).'s]'.
                       ' Row['.($db_stats['fetchrow']+0). '/'.round($db_stats['fetchrow_sec']+0,2).'s]'.
                      ' Rows['.($db_stats['fetchrows']+0).'/'.round($db_stats['fetchrows_sec']+0,2).'s]'.
                    ' Column['.($db_stats['fetchcol']+0). '/'.round($db_stats['fetchcol_sec']+0,2).'s]'.
                    ' Update['.($db_stats['update']+0).'/'.round($db_stats['update_sec']+0,2).'s]'.
                    ' Insert['.($db_stats['insert']+0). '/'.round($db_stats['insert_sec']+0,2).'s]'.
                    ' Delete['.($db_stats['delete']+0). '/'.round($db_stats['delete_sec']+0,2).'s]');
}

unset($config); // Remove this for testing

#print_vars(get_defined_vars());

// EOF
