#!/bin/expect

set serverFile [lindex $argv 0]
set serverLogin [lindex $argv 1]
set targetIP [lindex $argv 2]
set targetPort [lindex $argv 3]
set serverPassword [exec head -n 1 $serverFile]
set serverUser [exec echo $serverLogin | cut -d\@ -f1]
set timeout 20
set escseq [exec echo 'e' | tr 'e' '\035']

spawn sshpass -f $serverFile ssh -t $serverLogin "telnet $targetIP $targetPort"
expect "login:" {send "$serverUser\r"; exp_continue}
expect "Password:" {send "$serverPassword\r\r"; exp_continue}
send \r

set timeout 130
expect "Press RETURN to get started!" {sleep 20; send \r; exp_continue}
set timeout 20

expect ">" {send "enable\r"; exp_continue} 
expect "#" {send "erase startup-config\r"} 
expect "Continue?" {send \r}
sleep 3
send \r

expect "#" {send "reload\r"} 
expect "Proceed with reload?" {send \r} 
sleep 3
send \r
sleep 3

send "\x1d\r"
expect "telnet>" {send "quit\r"}
puts "The reset is done"
expect eof



