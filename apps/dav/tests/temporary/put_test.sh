#!/bin/bash

script_path="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

user='admin'
pass='admin'
server='localhost:8080'
proxy='http://172.18.16.164:8080'

testfile2="$script_path/zombie.jpg"

curl -X PUT -u $user:$pass --cookie "XDEBUG_SESSION=MROW4A;path=/;" --data-binary @"$testfile2" "http://$server/remote.php/webdav/zombie.jpg"
