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
expect "login:" {send "$serverUser\r"}
expect "Password:" {send "$serverPassword\r\r"}
send \r

set timeout 30
expect {
    "The password-recovery mechanism is enabled" {send "\x7e"; sleep 1; send "\x7e"; sleep 1; send "\x62"; sleep 1; send "\r";
        expect "switch:" {send "flash_init\r"}
        expect "switch:" {send "del flash:config.text\r"}
        expect "Are you sure you want to delete" {send "y\r"}
        expect "switch:" {send "del flash:vlan.dat\r"}
        expect "Are you sure you want to delete" {send "y\r"}
        expect "switch:" {send "boot\r"}

        set timeout 130
        expect "Press RETURN to get started!" {sleep 20; send \r}
        
        set timeout 20
        expect "enter the initial configuration dialog?" {send "no\r"; send \r}

        expect {
            "Switch>" {send "enable\r"}
            timeout {puts "timed out during reset"; exit 1 }
        }
    }
    "Readonly ROMMON initialized" {sleep 2;send "\x7e"; sleep 2 ; send "\x7e"; sleep 2 ; send "\x62"; sleep 2 ; send "\r";
        expect "rommon 1 >" {send "confreg 0x2142\r"}
        expect "rommon 2 >" {send "reset\r"}

        set timeout 130
        expect "enter the initial configuration dialog?" {send "no\r"; send \r}

        set timeout 20
        expect "Press RETURN to get started!" {sleep 20; send \r}

        expect ">" {send "enable\r"} 
        expect "#" {send "configure terminal\r"} 
        expect ")#" {send "config-register 0x2102\r"}
        expect ")#" {send "exit\r"}
        sleep 2
        send \r

        expect "#" {send "copy running-config startup-config\r"} 
        expect "Destination filename" {send "\r"} 
        expect "#" {send "disable\r"}
        expect {
            "Router>" {send \r}
            timeout {puts "timed out during reset"; exit 1 }
        }
    }
}


send "\x1d\r"
expect "telnet>" {send "quit\r"}
puts "The reset is done"
expect eof



