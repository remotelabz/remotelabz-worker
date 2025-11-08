#!/bin/bash
git fetch
git pull
composer update
php bin/console cache:clear
chown remotelabz-worker:www-data * -R
chmod g+w /opt/remotelabz-worker/var -R
systemctl daemon-reload
systemctl restart remotelabz-cache
systemctl restart remotelabz-worker
