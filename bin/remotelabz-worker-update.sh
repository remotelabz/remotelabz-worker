#!/bin/bash
cd /opt/remotelabz-worker
git fetch
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
WORK_DIR=$(pwd)
if [ ! -d "lib/network-bundle" ]; then
    echo "Clonage de network-bundle sur la branche $CURRENT_BRANCH..."
    git clone -b "$CURRENT_BRANCH" https://github.com/remotelabz/network-bundle lib/network-bundle
    git config --global --add safe.directory "$WORK_DIR/lib/network-bundle"
else
    echo "lib/network-bundle existe déjà, skip."
fi

# Clone remotelabz-message-bundle si le répertoire n'existe pas
if [ ! -d "lib/remotelabz-message-bundle" ]; then
    echo "Clonage de remotelabz-message-bundle sur la branche $CURRENT_BRANCH..."
    git clone -b "$CURRENT_BRANCH" https://github.com/remotelabz/remotelabz-message-bundle lib/remotelabz-message-bundle
    git config --global --add safe.directory "$WORK_DIR/lib/remotelabz-message-bundle"
else
    echo "lib/remotelabz-message-bundle existe déjà, skip."
fi

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