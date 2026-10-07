#!/bin/bash
cd /opt/remotelabz-worker

SOURCE_DIR="/opt/remotelabz-worker"
WORK_DIR="$(pwd)"

# Les dépôts sont publics : on essaie sans credentials (un token/header obsolète
# en config globale provoque un "Username for 'https://github.com':" même sur un
# dépôt public), puis avec la config normale si besoin (dépôt privé).
export GIT_TERMINAL_PROMPT=0

AUTH_ARGS=(-c credential.helper= -c http.extraHeader=)

safe_dir() {
    git config --global --get-all safe.directory 2>/dev/null | grep -qxF "$1" ||
        git config --global --add safe.directory "$1"
}

hote() {
    printf '%s\n' "$1" | sed -E -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#^[^/@]+@##' -e 's#[/:].*$##'
}

git_pub() {
    git "${AUTH_ARGS[@]}" "$@" || git "$@"
}

git_erreur() {
    echo "ERREUR : $1" >&2
    echo "  Dépôt distant    : $(git remote get-url origin 2>/dev/null || echo inconnu)" >&2
    echo "  Paramètres git   : (valeurs masquées)" >&2
    git config --show-origin --name-only --get-regexp '^(credential|http|url)\.' >&2 || true
    echo "  Causes possibles : credential/token obsolète, proxy (http.proxy)," >&2
    echo "                    ou dépôt distant privé nécessitant un jeton." >&2
    exit 1
}

bundle() {
    local nom="$1"
    local version="$2"
    local dir="$WORK_DIR/lib/$nom"

    if [ -e "$dir/.git" ]; then
        echo "$nom existe déjà, mise à jour."
        safe_dir "$dir"
        git_pub -C "$dir" fetch --tags ||
            echo "AVERTISSEMENT : fetch impossible pour $nom, version locale conservée."
        git_pub -C "$dir" checkout "$version" ||
            git_erreur "impossible de passer $nom en $version."
        return
    fi

    mkdir -p "$WORK_DIR/lib"
    if [ -d "$dir" ]; then
        local copie="$dir.copie-$(date +%Y%m%d%H%M%S)"
        echo "$nom existe sans dépôt git : déplacement vers $copie puis re-clonage."
        mv "$dir" "$copie" || git_erreur "impossible de déplacer $dir."
    fi

    echo "Clonage de $nom (version $version)"
    git_pub clone "https://github.com/remotelabz/$nom" "$dir" ||
        git_erreur "échec du clonage de https://github.com/remotelabz/$nom"
    git_pub -C "$dir" fetch --tags || true
    git_pub -C "$dir" checkout "$version" ||
        git_erreur "impossible de passer $nom en $version."
    safe_dir "$dir"
}

safe_dir "$SOURCE_DIR"
HOSTES="github.com"
for url in $(git remote get-url origin 2>/dev/null || true); do
    host=$(hote "$url")
    if printf '%s\n' "$host" | grep -Eq '^[A-Za-z0-9.-]+$'; then
        case " $HOSTES " in
            *" $host "*) ;;
            *) HOSTES="$HOSTES $host" ;;
        esac
    fi
done
for host in $HOSTES; do
    AUTH_ARGS+=(-c "credential.https://$host.helper=" -c "http.https://$host.extraHeader=")
done

git_pub fetch || git_erreur "git fetch a échoué, mise à jour interrompue."

bundle network-bundle 1.0.4
bundle remotelabz-message-bundle 1.0.8

mv $SOURCE_DIR/config/packages/messenger.yaml ~/
git restore $SOURCE_DIR/config/packages/messenger.yaml
mv $SOURCE_DIR/config/packages/dev/web_profiler.yaml ~/
git restore $SOURCE_DIR/config/packages/dev/web_profiler.yaml
git_pub pull || git_erreur "git pull a échoué, mise à jour interrompue."
mv ~/messenger.yaml $SOURCE_DIR/config/packages/messenger.yaml
mv ~/web_profiler.yaml $SOURCE_DIR/config/packages/dev/web_profiler.yaml

for service_file in "$SOURCE_DIR"/bin/systemd/*; do
    filename=$(basename "$service_file")
    target="/etc/systemd/system/$filename"

    # Créer le lien symbolique
    ln -sf "$service_file" "$target"
done

# Rafraîchit /usr/local/bin/composer avec le composer.phar du dépôt : les
# versions antérieures à 2.10 affichent "curl_close() is deprecated" en PHP 8.5.
if [ "$(id -u)" -eq 0 ] && [ -f "$SOURCE_DIR/composer.phar" ]; then
    if ! cmp -s "$SOURCE_DIR/composer.phar" /usr/local/bin/composer; then
        echo "Mise à jour de /usr/local/bin/composer depuis composer.phar"
        cp "$SOURCE_DIR/composer.phar" /usr/local/bin/composer
        chmod 755 /usr/local/bin/composer
    fi
fi

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
