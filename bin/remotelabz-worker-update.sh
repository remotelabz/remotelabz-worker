#!/bin/bash
composer update
php bin/console cache:clear
chown remotelabz-worker:www-data * -R
chmod g+w /opt/remotelabz-worker/var -R
systemctl daemon-reload
service remotelabz-worker restart

