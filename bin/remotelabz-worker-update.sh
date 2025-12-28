#!/bin/bash
cd /opt/remotelabz-worker
git fetch
mv /opt/remotelabz-worker/config/packages/messenger.yaml ~/
git restore /opt/remotelabz-worker/config/packages/messenger.yaml
mv /opt/remotelabz-worker/config/packages/dev/web_profiler.yaml ~/
git restore /opt/remotelabz-worker/config/packages/dev/web_profiler.yaml
git pull
mv ~/messenger.yaml /opt/remotelabz-worker/config/packages/messenger.yaml
mv ~/web_profiler.yaml /opt/remotelabz-worker/config/packages/dev/web_profiler.yaml
composer update
php bin/console cache:clear
chown remotelabz-worker:www-data * -R
chmod g+w /opt/remotelabz-worker/var -R
systemctl daemon-reload
systemctl restart remotelabz-cache
systemctl restart remotelabz-worker
