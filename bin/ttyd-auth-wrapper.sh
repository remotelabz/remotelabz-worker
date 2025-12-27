#!/bin/sh
set -eu

CONTAINER="$1"

[ -z "$CONTAINER" ] && {
  echo "Usage: $0 <container>"
  exit 1
}

printf "Instance %s \n" $CONTAINER
printf "Login: "
IFS= read -r USER
USER=$(printf "%s" "$USER" | tr -d '\r\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')

[ -z "$USER" ] && {
  echo "Empty login"
  exit 1
}

printf "Password: "
stty -echo
IFS= read -r PASS
stty echo
echo

# Auth PAM DANS le conteneur
if ! printf "%s" "$PASS" | \
  lxc-attach -n "$CONTAINER" -- \
  pamtester login "$USER" authenticate >/dev/null 2>&1
then
  echo "Authentication failed"
  sleep 2
  exit 1
fi

# Shell login utilisateur (dans le conteneur)
exec lxc-attach -n "$CONTAINER" -- su -l "$USER"
