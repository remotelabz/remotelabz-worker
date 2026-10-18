# Collecte de l'IP des instances (qemu-guest-agent)

> Côté **front** : `doc/instance-ip-collection.md` (plan complet).
> Ce document décrit le volet **worker** de la collecte d'IP.

## Principe

Le front demande l'IP d'une `DeviceInstance` via un message
`DeviceInfoRequestMessage` (couple requête/réponse avec `DeviceInfoMessage`).
Le worker résout l'IP dans `App\Service\Instance\DeviceIpResolver` :

| Cas | Source |
|---|---|
| Conteneur **LXC** | `lxc-info -n <uuid> -iH` |
| VM **QEMU** | qemu-guest-agent (`guest-network-get-interfaces`) |
| VM **QEMU sans QGA** | repli : table ARP du pont (`ip -4 neigh show dev <bridge>`, par MAC) puis baux DHCP Kea (`/var/lib/kea/kea-leases4.csv`) |

## Canal qemu-guest-agent

`InstanceManager::create_qemu_device()` ajoute à la ligne de commande QEMU
(bucket `agent`, avant `access`) :

```
-chardev socket,id=qga0,path=/run/remotelabz/qga-<uuid>.sock,server=on,wait=off
-device virtio-serial-pci
-device virtserialport,chardev=qga0,name=org.qemu.guest_agent.0
```

Le socket est créé par QEMU dès le lancement de la VM, mais **l'agent doit être
installé et démarré dans l'OS invité** : l'IP n'est disponible qu'après quelques
dizaines de secondes (le timer front rattrape au tick suivant).

### Prérequis sur les images invitées

Installer `qemu-guest-agent` et l'activer dans les images de référence :

```bash
# Debian / Ubuntu
apt-get install -y qemu-guest-agent
systemctl enable --now qemu-guest-agent

# RHEL / Rocky / Alma
dnf install -y qemu-guest-agent
systemctl enable --now qemu-guest-agent
```

### Répertoire des sockets

`/run` est un tmpfs : le répertoire `/run/remotelabz` (perm. `0750`) est recréé
au démarrage par `config/tmpfiles/remotelabz-worker.conf` (installé dans
`/usr/lib/tmpfiles.d/`). `create_qemu_device()` fait aussi un `mkdir` en filet de
sécurité.

## Points d'attention

- Les VM **déjà démarrées** au moment du déploiement n'ont pas le canal QGA
  avant redémarrage : le repli ARP/bail couvre l'attente.
- Une VM sans trafic récent peut ne pas apparaître dans la table ARP : le bail
  DHCP Kea prend alors le relais si la VM a obtenu une adresse.
- Le service ne conserve **aucun état** : il relit le système à chaque demande.
