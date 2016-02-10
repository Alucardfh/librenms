#!/usr/bin/env php
<?php

// connect to gearman
if (false === $sh = fsockopen('127.0.0.1', '4730', $errno, $errstr, 10)) {
    fwrite(STDERR, sprintf('Unable to connect to gearman: %s: %s', $errno, $errstr) . "\n");

    exit(1);
}

fwrite($sh, "workers\n");

$workers = array();

$gearman = new GearmanClient();
$gearman->addServer();

$workerCount=0;

// Count registered workers
while ((!feof($sh)) && (".\n" !== $line = fgets($sh)))
{
    $pattern='/.*_worker_.*/';
    if(preg_match($pattern, $line))
    {
        $workerCount++;
    }
}
fclose($sh);

for($i=1;$i<=$workerCount;$i++)
{
    echo("Asking _worker_" . $i ." to terminate \n");
    $gearman->doHighBackground('_worker_'.$i,'terminate');
}


?>
