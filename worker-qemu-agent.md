# Options QEMU `qemu_agent` / `cpu_host` — modifications à faire dans le worker

> **Statut** : implémenté côté **front** (`remotelabz`).
> Ce document décrit les modifications à apporter côté **worker**
> (`remotelabz-worker`), qui n'a pas été modifié.

## 1. Nouveaux champs dans le descripteur

Deux cases à cocher ont été ajoutées au formulaire de définition des devices
(`/admin/devices/new`, `/admin/devices/{id}/edit`) et à la page de
visualisation d'un device. Elles ne sont affichées que lorsque l'OS sélectionné
utilise l'hyperviseur **qemu**. Pour tout autre hyperviseur, elles sont
masquées et forcées à `false`.

Côté front, les deux champs sont sur l'entité `Device`
(`src/Entity/Device.php`) et sont sérialisés dans le groupe `worker`. Ils sont
donc présents dans `deviceInstance.device` du JSON envoyé au worker :

| Clé JSON | Type | Défaut | Signification |
|---|---|---|---|
| `qemu_agent` | bool | `false` | Expose le canal QEMU guest agent (virtio-serial + socket Unix) à la VM |
| `cpu_host` | bool | `false` | Démarre QEMU avec `-cpu host` au lieu de `-cpu max` |

Exemple de fragment reçu par le worker :

```json
{
  "deviceInstances": [
    {
      "uuid": "0d7c…",
      "device": {
        "name": "Windows-10",
        "hypervisor": { "name": "qemu" },
        "qemu_agent": true,
        "cpu_host": true
      }
    }
  ]
}
```

Rétrocompatibilité : un descripteur produit par un ancien front ne contient
pas ces clés. Le worker doit donc utiliser `false` par défaut
(`!empty($deviceInstance['device']['qemu_agent'])`).

### 1.1 Migration côté front

La migration `Version20261123120000` ajoute les deux colonnes (`DEFAULT 0`) et
passe `qemu_agent = 1` sur les devices existants dont l'hyperviseur est
`qemu` : le worker continuait jusqu'ici à exposer le guest agent dans tous les
cas, on préserve donc le comportement des VM existantes. Les nouveaux devices
sont créés avec les deux options décochées, et `cpu_host` reste `0` partout
(le comportement actuel est `-cpu max`).

À noter : dans l'éditeur de lab, un node est une **copie** du device template
au moment de son ajout. Modifier le template ensuite ne change pas les copies
déjà présentes dans les labs (comportement identique aux autres options
`network_card_type` / `minimum_network_interfaces`).

**Ordre de déploiement** : déployer le front (avec sa migration) avant le
worker. Un descripteur émis par un ancien front ne contient pas les clés ; le
worker les interprète alors comme `false`, ce qui désactiverait le guest agent
pour toutes les VM pendant la fenêtre de transition.

## 2. Modification n°1 — `-cpu host` dans `qemu_start()`

Fichier : `src/Service/Instance/InstanceManager.php`.

Actuellement la ligne de base de la commande QEMU contient `-cpu max` en dur
(autour de la **ligne 1346**) :

```php
$command = [
    'qemu-system-' . $arch,
    '-enable-kvm',
    '-machine', 'accel=kvm:tcg',
    '-cpu', 'max',
    '-display', 'none',
    '-daemonize',
    '-name', "$uuid",
    '-nodefaults'
];
```

### 2.1 Ajouter un paramètre à `qemu_start()`

Autour de la **ligne 1312**, ajouter un troisième paramètre optionnel :

```php
public function qemu_start(array $parameters, string $uuid, bool $cpuHost = false)
```

Puis remplacer `'-cpu', 'max',` par :

```php
'-cpu', $cpuHost ? 'host' : 'max',
```

Le reste de la fonction est inchangé.

### 2.2 Passer la valeur depuis `create_qemu_device()`

Autour de la **ligne 5643**, l'appel actuel est :

```php
$qemu_start_return = $this->qemu_start($parameters, $deviceInstance['uuid']);
```

Le remplacer par :

```php
$cpuHost = !empty($deviceInstance['device']['cpu_host']);
$qemu_start_return = $this->qemu_start($parameters, $deviceInstance['uuid'], $cpuHost);
```

`qemu_start()` n'a qu'un seul appelant dans le worker : cette modification est
donc localisée.

### 2.3 Remarque

`-cpu host` exige KVM. Le worker lance déjà QEMU avec `-enable-kvm` et
`-machine accel=kvm:tcg` ; sur un hôte sans KVM, `-cpu host` fera échouer le
démarrage. C'est à l'administrateur de ne cocher l'option que sur un hôte KVM
(côté front, l'aide du champ le précise).

## 3. Modification n°2 — QEMU guest agent conditionnel

Fichier : `src/Service/Instance/InstanceManager.php`, dans
`create_qemu_device()`.

Actuellement le canal guest agent est ajouté **dans tous les cas** (bloc
commençant **ligne 5585** environ) :

```php
// Canal qemu-guest-agent : un socket Unix par instance. ...
$qgaDirectory = '/run/remotelabz';
if (!is_dir($qgaDirectory)) {
    @mkdir($qgaDirectory, 0750, true);
}
$qgaSocket = $qgaDirectory . '/qga-' . $deviceInstance['uuid'] . '.sock';
array_push(
    $parameters['agent'],
    '-chardev', 'socket,id=qga0,path=' . $qgaSocket . ',server=on,wait=off',
    '-device',  'virtio-serial-pci',
    '-device',  'virtserialport,chardev=qga0,name=org.qemu.guest_agent.0'
);
```

Il faut l'encadrer dans un test sur le nouveau champ :

```php
// Canal qemu-guest-agent : uniquement si le device a l'option qemu_agent.
// Un socket Unix est créé par instance ; l'agent doit être présent ET démarré
// dans l'invité pour que l'IP remonte (voir DeviceIpResolver et le repli
// ARP / bail DHCP).
if (!empty($deviceInstance['device']['qemu_agent'])) {
    $qgaDirectory = '/run/remotelabz';
    if (!is_dir($qgaDirectory)) {
        @mkdir($qgaDirectory, 0750, true);
    }
    $qgaSocket = $qgaDirectory . '/qga-' . $deviceInstance['uuid'] . '.sock';
    array_push(
        $parameters['agent'],
        '-chardev', 'socket,id=qga0,path=' . $qgaSocket . ',server=on,wait=off',
        '-device',  'virtio-serial-pci',
        '-device',  'virtserialport,chardev=qga0,name=org.qemu.guest_agent.0'
    );
}
```

Le bucket `'agent' => []` de `$parameters` reste déclaré : quand l'option est
désactivée, il reste simplement vide, et l'ordre des arguments QEMU (bucket
`agent` avant `access`) est conservé.

### 3.1 Conséquence sur la résolution d'IP (à vérifier)

Quand `qemu_agent` est désactivé, le worker ne crée plus de socket QGA.
`DeviceIpResolver::collectQemu()` tente d'abord `QemuGuestAgentClient`, puis
retombe sur la table ARP du pont et les baux DHCP Kea. Le repli existant doit
donc rester fonctionnel : c'est le chemin normal pour les VM sans guest agent.

Amélioration facultative (non obligatoire) : dans `DeviceIpResolver`, ne pas
tenter le client QGA quand `device.qemu_agent` est faux, afin d'éviter une
tentative de connexion inutile à chaque collecte :

```php
$interfaces = !empty($deviceInstance['device']['qemu_agent'])
    ? $this->qga->getInterfaces($uuid)
    : null;
```

## 4. Rétrocompatibilité

| Front \ Worker | Worker à jour | Worker ancien |
|---|---|---|
| **Ancien front** (clés absentes) | `false` par défaut → `-cpu max`, pas de QGA (déployer le front en premier) | inchangé |
| **Nouveau front**, options décochées | `-cpu max`, pas de QGA | options ignorées → `-cpu max` + QGA toujours présent |
| **Nouveau front**, options cochées | `-cpu host` + QGA | options ignorées → comportement actuel |

## 5. Tests worker

1. `cpu_host = true` → la commande QEMU contient `-cpu host` et **pas**
   `-cpu max`.
2. `cpu_host = false` ou clé absente → `-cpu max` (comportement actuel).
3. `qemu_agent = true` → les arguments `-chardev socket,id=qga0,…`,
   `-device virtio-serial-pci` et
   `-device virtserialport,chardev=qga0,name=org.qemu.guest_agent.0` sont
   présents.
4. `qemu_agent = false` ou clé absente → aucun de ces trois groupes
   d'arguments, et aucun socket `/run/remotelabz/qga-<uuid>.sock` créé.
5. `qemu_agent = false` → la résolution d'IP retombe bien sur ARP / baux DHCP.
6. Device LXC / natif / physique → aucun changement (champs ignorés).
