#!/bin/bash

if [ "$(id -u)" -eq 0 ]
then
   echo "Observium script shouldn't be run as root, use the observium user"
   exit 1
fi

if [ $# -ne 4 ]
then
	echo "USAGE: $0 PREFIX DOMAIN RANGE_START RANGE_END"
	echo -e "### Example: $0 lmigh lon.compute.pgs.com 1 500"
	exit 1
fi


addCommand="/data/librenms/addhost.php "

prefix=$1
domain=$2
start=$3
end=$4


for nodeNumber in $(seq $start $end)
do
	node=${prefix}$(printf "%03d" $nodeNumber)
	fqdn="${node}.$domain"
	$addCommand $fqdn
done
