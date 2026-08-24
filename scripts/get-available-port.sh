#!/bin/bash
# read LOWERPORT UPPERPORT < /proc/sys/net/ipv4/ip_local_port_range
# Ubuntu26, you have to set ProcSubset=all in apache2 service to access to /proc
LOWERPORT=32768
UPPERPORT=60999
while :
do
        PORT="`shuf -i $LOWERPORT-$UPPERPORT -n 1`"
        ss -ltn | grep -q ":$PORT " || break
done
echo -n $PORT