#!/bin/bash
#To change branch, do git checkout dev or git checkout master before
git fetch
git reset --hard
git pull
bin/remotelabz-worker-update.sh