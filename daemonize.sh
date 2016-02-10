
nbWorker=24

for i in $(seq 1 $nbWorker)
do
    ./worker.php $i >/dev/null
done

echo "Started $nbWorker workers !"
