#!/usr/bin/env php
<?php
    function getTimestamp()
    {
        return date("H:i:s") . substr((string)microtime(), 1, 8);
    }

    function printDebug($string)
    {
        global $debugFile;
        if(DEBUG)
        {
            fwrite($debugFile, $string);
        }
    }


    $number=$argv[1];
    define('DEBUG',0);

    $pid = pcntl_fork();
    if ($pid == -1) {
         die('could not fork');
    } else if ($pid)
    {
    } else
    {

        include_once("includes/defaults.inc.php");
        include_once("config.php");

        include_once("includes/definitions.inc.php");
        include_once("includes/functions.inc.php");
        include_once("includes/polling/functions.inc.php");

        declare(ticks = 1);

        //Open a pipe with an rrdtool process to update the rrds
        rrdtool_pipe_open($rrd_process, $rrd_pipes);

        $name="worker_".$number.".log";

        if(DEBUG)
        {
            global $debugFile;
            $debugFile=fopen("/tmp/$name",'w+');
        }

        //Just an array that contain the information about the worker
        $context = array(
            'id' => $number, //Need to be unique
            'pid' => getmypid(),
            'terminate' => false,
        );

        //Callback for SIGTEM to exit properly
        pcntl_signal(
            SIGTERM,
            function () use (&$context)
            {
                //Set the flag to end the main loop
                $context['terminate'] = true;
            }
        );

        $worker = new GearmanWorker();
        $worker->addOptions(GEARMAN_WORKER_NON_BLOCKING); //Set non blocking method so we can exit properly
        $worker->addServer();

        //Register the actual processing function
        $worker->addFunction(
            'poll_device_task',
            function (GearmanJob $job) use ($context,$rrd_process,$rrd_pipes,$debugFile)
            {
                printDebug(sprintf('[%s] Worker %s Got work ! ', getTimestamp(), $context['id']) . "\n");
                $workload=unserialize($job->workload()); //Unserialize the work !
                $device=$workload[0];
                $options=$workload[1];
                $doing=$workload[2];
                printDebug(sprintf('[%s] Worker %s will process device %d ! ', getTimestamp(), $context['id'],$device['device_id']) . "\n");
                $start=utime();
                //Call the polling method
                poll_device($device, $options);
                $end=utime();
                $poller_time=substr($end-$start, 0, 5); //Just get the number of second and three digits
                dbInsert(array('type' => 'poll', 'doing' => $doing, 'start' => $start, 'duration' => $poller_time, 'devices' => $device['device_id'] ), 'perf_times');
                printDebug(sprintf('[%s] Worker %s Finished work ! (%s sec) ', getTimestamp(), $context['id'],$poller_time) . "\n");
                return substr($end-$start, 0, 5);
            }
        );

        //Register the exit function
        $worker->addFunction(
            '_worker_' . $context['id'],
            function (GearmanJob $job) use ($context,$rrd_process,$rrd_pipes) {
                switch ($job->workload()) {
                    case 'terminate':
                        rrdtool_pipe_close($rrd_process, $rrd_pipes);
                        posix_kill($context['pid'], SIGTERM); # exec(sprintf('/bin/kill -s TERM %d', $context['pid']));
                        //Trigger our own exit/cleanup using SIGTERM so the worker can tell the server it has finished properly

                        break;
                }
            }
        );

        printDebug(sprintf('[%s] Worker %s is ready to work ! ', getTimestamp(), $context['id']) . "\n");

        // work on jobs as they're available
        while (
            (!$context['terminate']) //will quit if this is set
            &&
            (
                $worker->work()                                 //If there is work dispatch it
                || (GEARMAN_IO_WAIT == $worker->returnCode())   //if not is it because the server is busy
                || (GEARMAN_NO_JOBS == $worker->returnCode())   //is it because there is nothing to do
            )
        )
        {
            if (GEARMAN_SUCCESS == $worker->returnCode()) //Anyway if everithing is fine go on
            {
                continue;
            }

            $worker->wait(); //if something was fishy wait for the server to talk to us
        }

        $worker->unregisterAll(); //Cleanup our registered functions

        if($debug)
        {
            fclose($debugFile);
        }

        //Cleanup the pipe to the rrdtool process
        rrdtool_pipe_close($rrd_process, $rrd_pipes);
    }


?>
