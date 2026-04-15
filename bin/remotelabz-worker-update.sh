#!/bin/bash
cd /opt/remotelabz-worker
git fetch
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
WORK_DIR=$(pwd)
SOURCE_DIR="/opt/remotelabz-worker"
if [ ! -d "lib/network-bundle" ]; then
    echo "Clonage de network-bundle"
    git clone https://github.com/remotelabz/network-bundle "$WORK_DIR/lib/network-bundle"
    git -C "$WORK_DIR/lib/network-bundle" fetch --tags
    git -C "$WORK_DIR/lib/network-bundle" checkout 1.0.4
    git config --global --add safe.directory "$WORK_DIR/lib/network-bundle"
else
    echo "lib/network-bundle existe déjà, mise à jour."
    git -C "$WORK_DIR/lib/network-bundle" fetch --tags
    git -C "$WORK_DIR/lib/network-bundle" checkout 1.0.4
fi

# Clone remotelabz-message-bundle si le répertoire n'existe pas
if [ ! -d "lib/remotelabz-message-bundle" ]; then
    echo "Clonage de remotelabz-message-bundle"
    git clone https://github.com/remotelabz/remotelabz-message-bundle "$WORK_DIR/lib/remotelabz-message-bundle"
    git -C "$WORK_DIR/lib/remotelabz-message-bundle" fetch --tags
    git -C "$WORK_DIR/lib/remotelabz-message-bundle" checkout 1.0.6
    git config --global --add safe.directory "$WORK_DIR/lib/remotelabz-message-bundle"
else
    echo "lib/remotelabz-message-bundle existe déjà, mise à jour."
    git -C "$WORK_DIR/lib/remotelabz-message-bundle" fetch --tags
    git -C "$WORK_DIR/lib/remotelabz-message-bundle" checkout 1.0.6
fi

mv $SOURCE_DIR/config/packages/messenger.yaml ~/
git restore $SOURCE_DIR/config/packages/messenger.yaml
mv $SOURCE_DIR/config/packages/dev/web_profiler.yaml ~/
git restore $SOURCE_DIR/config/packages/dev/web_profiler.yaml
git pull
mv ~/messenger.yaml $SOURCE_DIR/config/packages/messenger.yaml
mv ~/web_profiler.yaml $SOURCE_DIR/config/packages/dev/web_profiler.yaml

for service_file in "$SOURCE_DIR"/bin/systemd/*; do
    filename=$(basename "$service_file")
    target="/etc/systemd/system/$filename"

    # Créer le lien symbolique
    ln -sf "$service_file" "$target"
done
composer update
php bin/console cache:clear
chown remotelabz-worker:www-data * -R
chmod g+w /opt/remotelabz-worker/var -R
systemctl daemon-reload
systemctl restart remotelabz-cache
systemctl restart remotelabz-worker