# Guide de partitionnement disque — Worker Remotelabz

> Document côté **worker**. Décrit la disposition LVM attendue : un VG pour le
> système Ubuntu, un second VG pour les charges Remotelabz (VMs QEMU, conteneurs LXC,
> instances utilisateurs).

## 1. Vue d'ensemble

![Schéma de partitionnement](./images/worker-disk-partition.svg)

Deux **groupes de volumes** (VG) sont créés sur le disque :

| VG | Rôle | Contenu |
|---|---|---|
| `ubuntu-vg` | Système (défaut Ubuntu) | LV `ubuntu-lv` ≥ 35 GB → `/` |
| `rlz-vg` | Charges Remotelabz | `rlz-lv`, `lib-lxc`, LV conteneurs |

## 2. Détail des volumes logiques

| LV | VG | Taille | Point de montage | Usage |
|---|---|---|---|---|
| `ubuntu-lv` | `ubuntu-vg` | ≥ 35 GB | `/` | Système Ubuntu |
| `rlz-lv` | `rlz-vg` | 60 % de l'espace libre restant | `/opt` | VMs QEMU |
| `lib-lxc` | `rlz-vg` | 100 GB (fixe) | `/var/lib/lxc` | Conteneurs LXC |
| LV conteneurs | `rlz-vg` | 40 % de l'espace libre restant | *(instances utilisateurs)* | Conteneurs utilisateurs |

## 3. Schéma ASCII

```
┌───────────────────────────────────────────────────────────────────────────────────┐
│                              DISQUE PHYSIQUE                                        │
├───────────────────────┬───────────────────────────────────────────────────────────┤
│      ubuntu-vg        │                        rlz-vg                             │
│      (≥ 35 GB)        │                  (tout l'espace restant)                  │
│   ┌───────────────┐   │   ┌───────────────────────────────────────────────────┐   │
│   │  ubuntu-lv    │   │   │  rlz-lv    60 % libre restant   →  /opt   (QEMU)   │   │
│   │   ≥ 35 GB     │   │   ├───────────────────────────────────────────────────┤   │
│   │   →  /        │   │   │  lib-lxc   100 GB (fixe)        →  /var/lib/lxc     │   │
│   │ (OS Ubuntu)   │   │   ├───────────────────────────────────────────────────┤   │
│   └───────────────┘   │   │  conteneurs 40 % libre restant →  instances user   │   │
│                       │   └───────────────────────────────────────────────────┘   │
└───────────────────────┴───────────────────────────────────────────────────────────┘
```

Ordre d'allocation dans `rlz-vg` :

```
        espace libre F  =  taille(rlz-vg)
                │
                ├─ 1) lib-lxc ......................... 100 GB  (fixe)
                │
   F - 100 GB   │
                ├─ 2) rlz-lv .................. 60 % de (F - 100 GB)  →  /opt
                │
                └─ 3) conteneurs .............. 40 % de (F - 100 GB)  →  instances user
```

## 4. Mise en œuvre

### 4.1 `ubuntu-vg` / `ubuntu-lv` (≥ 35 GB)

Ces noms sont ceux produits **par défaut par l'installateur Ubuntu** en mode LVM guidé.
Pour garantir au moins 35 GB au système, sélectionner l'option LVM au partitionnement puis
ajuster la taille du volume `ubuntu-lv` dans l'assistant (`/` ≥ 35 GB).

### 4.2 Création de `rlz-vg` et de ses LV

```bash
# Créer le VG avec tout l'espace disque restant
vgcreate rlz-vg /dev/<partition_ou_disque>

# 1) lib-lxc : 100 GB fixes pour les conteneurs LXC
lvcreate -L 100G -n lib-lxc rlz-vg

# 2) rlz-lv : 60 % de l'espace ENCORE libre (calculé après lib-lxc)
lvcreate -l 60%FREE -n rlz-lv rlz-vg

# 3) conteneurs utilisateurs : tout l'espace libre restant (≈ 40 %)
lvcreate -l 100%FREE -n rlz-containers rlz-vg
```

> `-l 60%FREE` et `-l 100%FREE` se réfèrent à l'espace libre **au moment de la commande** :
> l'ordre ci-dessus implémente donc directement l'interprétation du §2.

### 4.3 Formater et monter

```bash
mkfs.ext4 /dev/rlz-vg/rlz-lv
mkfs.ext4 /dev/rlz-vg/lib-lxc
mkfs.ext4 /dev/rlz-vg/rlz-containers

mkdir -p /opt /var/lib/lxc
mount /dev/rlz-vg/rlz-lv /opt
mount /dev/rlz-vg/lib-lxc /var/lib/lxc
```

Persistance dans `/etc/fstab` (identifiants stables, pas le nom du VG) :

```
/dev/mapper/rlz--vg-rlz--lg         /opt            ext4  defaults  0 2
/dev/mapper/rlz--vg-lib--lxc        /var/lib/lxc    ext4  defaults  0 2
```

> Le second LV conteneurs n'est monté qu'au premier démarrage d'une instance utilisateur
> (ou monté sur son point dédié selon la configuration du worker).

### 4.4 Vérification

```bash
vgs                          # espace libre/utilisé par VG
lvs -o +lv_size,vg_free      # détail des LV et espace libre
df -h /opt /var/lib/lxc      # montages effectifs
```

## 5. Variante : provisionnement *thin* (optionnel)

Pour sur-allouer / créer des snapshots instantanés, on peut créer les LV de `rlz-vg`
comme volumes *thin* dans un pool commun :

```bash
lvcreate -L 100%FREE --thinpool rlz-pool rlz-vg

lvcreate --type thin -V 100G  -n lib-lxc        rlz-vg/rlz-pool
lvcreate --type thin -V 60%PV -n rlz-lv         rlz-vg/rlz-pool
lvcreate --type thin -V 100%FREE -n rlz-containers rlz-vg/rlz-pool
```

⚠️ Avec un pool thin, surveiller le remplissage réel (`lvs -o +data_percent,metadata_percent`)
car une sur-allocation peut épuiser le pool physique même si les LV apparents semblent
larges.

## 6. Points d'attention

| Sujet | Recommandation |
|---|---|
| Taille du VG système | Ne pas descendre sous 35 GB pour `/` (mises à jour, logs, paquets). |
| `lib-lxc` fixe | Prévoir de la marge : les conteneurs LXC grossissent vite (`lvextend` à chaud possible). |
| Espace libre | Ne pas allouer 100 % de `rlz-vg` : laisser ~5 % libre pour snapshots / extensions. |
| Migration | Utiliser les chemins `/dev/mapper/...` dans `fstab` plutôt que `/dev/rlz-vg/...`. |
