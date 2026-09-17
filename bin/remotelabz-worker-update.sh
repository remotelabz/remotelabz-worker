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
chown :remotelabz-worker /var/lib/lxc
chmod g+w /var/lib/lxc
systemctl daemon-reload

# Vérifie que chaque unité systemd de bin/systemd est bien activée et démarrée.
# Aucun restart si l'unité est déjà active.
for service_file in "$SOURCE_DIR"/bin/systemd/*; do
    filename=$(basename "$service_file")
    unit_type="${filename##*.}"

    case "$unit_type" in
        service|timer)
            ;;
        *)
            # Les slices sont des unités statiques : rien à activer ni à démarrer
            continue
            ;;
    esac

    enabled_state=$(systemctl is-enabled "$filename" 2>/dev/null)
    if [ "$enabled_state" != "enabled" ] && [ "$enabled_state" != "linked" ]; then
        echo "Activation de $filename (état actuel : $enabled_state)"
        systemctl enable "$filename"
    fi

    active_state=$(systemctl is-active "$filename" 2>/dev/null)
    if [ "$active_state" != "active" ]; then
        echo "Démarrage de $filename (état actuel : $active_state)"
        systemctl start "$filename"
    else
        echo "$filename est déjà actif, aucun restart"
    fi
done