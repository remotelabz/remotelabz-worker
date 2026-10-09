# Plan de modification — Worker : interconnexion des labs « shared »

> Issue associée : [#212 Shared lab](https://github.com/remotelabz/remotelabz-worker/issues/212)
> — *« When a lab has the 'shared' status, all same labs in the same group must be reachable »*
>
> Document côté **worker**. Le plan côté front est détaillé dans [`shared-labs-front-plan.md`](./shared-labs-front-plan.md).

## 1. Objectif

Lorsqu'une **règle de partage** existe côté front — nouvelle entité `LabShare`
`(lab S, lab cible L, groupe G)` (le booléen `Lab::$shared` est remplacé par ces
règles, cf. plan front §2) — les instances de `S` et de `L` appartenant à `G`
doivent être **mutuellement atteignables**, que les instances soient :

- hébergées par le **même worker** (same-worker) ;
- ou réparties sur **plusieurs workers** (cross-worker).

Le worker reçoit la topologie de partage depuis le front via un nouveau message de type
`SecurityMessage` et la traduit en règles **iptables** + routes IP. Le worker est
**agnostic** : il applique les liens reçus, sans connaître les règles `LabShare`
(leur interprétation — quelles instances lier — est faite intégralement par le front).

### Périmètre strict : le groupe d'instances, pas le lab

Le partage est **toujours scopé au groupe** porté par la règle, jamais au lab
(template) lui-même. Un lab peut être disponible dans plusieurs groupes et instancié
indépendamment par chacun :

```
Règle : (S, L, G1)
Groupe G1 :  prof lance S ; utilisateur A rejoint L        →  S(G1) ↔ L(A, G1)  ✅
Groupe G2 :  utilisateurs instancient aussi L              →  S(G1) ↔ L(G2)    ❌
             (aucune règle (S, L, G2))
```

Les instances du lab `L` appartenant à `G2` ne sont **jamais** liées à l'instance de `S`
lancée pour `G1` : les deux groupes produisent deux topologies indépendantes. Le worker
doit appliquer la **réunion (union)** des topologies de tous les groupes sans qu'elles
s'écrasent mutuellement (cf. §3 et §6).

## 2. Architecture réseau existante (état des lieux)

| Élément | Emplacement | Comportement |
|---|---|---|
| Bridge OVS par lab | `InstanceManager::Create_And_Secure_OVS` (`src/Service/Instance/InstanceManager.php:4754`) | nom `br-<substr(uuid,0,8)>` |
| Chaîne iptables par lab | idem (`:4819-4826`) | chaîne `<br>_forward` sautée **inconditionnellement** depuis `FORWARD` |
| Politique `FORWARD` | `bin/remotelabz-worker-network-up:168` | **DROP** |
| Retour du trafic | `bin/remotelabz-worker-network-up:128-137` | règle globale `ESTABLISHED,RELATED` acceptée |
| Règles acceptées | idem | `br ↔ iface internet`, `br ↔ VPN` uniquement |
| Routes inter-workers | — | **aucune** : un worker n'a pas le chemin vers les sous-réseaux des autres workers |
| Masquerading | `network-up` + `InstanceManager::connectToInternet:2286` | `MASQUERADE -s LAB_NETWORK -o <iface>` |

Conséquences importantes :

- Tous les paquets transitent par **toutes** les chaînes de labs (saut inconditionnel) :
  une **chaîne globale** dédiée suffit, son ordre dans `FORWARD` est indifférent
  (les chaînes de labs ne contiennent que des `ACCEPT`, elles ne droppent rien).
- Le cross-worker exige d'ajouter des **routes** vers les réseaux distants via l'IP du worker distant
  (`WORKER_IP`, réseau de data `192.168.11.0/24`, les workers se voient directement).
- Pas de NAT de principe : vraies IPs des labs conservées — d'où le correctif MASQUERADE (§6).

## 3. Mécanisme retenu

Le front **calcule et diffuse la topologie d'un groupe** ; le worker **applique**
un état complet (flux *rebuild*, pas incrémental) :

1. Réception d'un `SecurityMessage` : `{ group: <uuid>, links: [...] }`.
2. Le message est identifié par son **`group`** : c'est la **clé d'état** du worker.
   L'entrée de ce groupe dans l'état local est **remplacée** par le nouveau contenu
   (y compris si `links` est vide → le groupe n'a plus aucun lien).
3. Filtrage local : seuls les liens concernant **ce worker** sont retenus
   (`a.workerIp` ou `b.workerIp` == `app.worker.ip`).
4. Reconstruction de la chaîne globale `shared_forward` à partir de **la réunion de tous
   les groupes** de l'état local :
   - `create_chain('shared_forward')` (idempotent, garde `isChainExists` déjà présent) ;
   - saut `FORWARD → shared_forward` si absent ;
   - `flush_chain('shared_forward')` puis réappend des règles :
     - lien same-worker **et** cross-worker : `ACCEPT -s <netA> -d <netB>` et l'inverse.
5. Reconstruction des routes à partir de la même union :
   - pour chaque lien dont l'extrémité distante est sur un autre worker :
     `ip route add <netDist> via <workerIpDist>` (table principale) ;
   - état persisté dans `var/shared-security.json` :
     `{ "<group-uuid>": { "links": [...] }, ... }` → purge des routes devenues obsolètes.
6. État local **vide** après remplacement → flush de la chaîne + purge de toutes les routes stockées.

> **Point critique (multi-groupes)** : le `flush_chain` ne se fait jamais « à la volée »
> sur la base du dernier message reçu, mais sur l'**union reconstituée de tous les groupes**
> présents dans l'état. Sans cela, un message pour `G2` effacerait les règles de `G1`
> alors que `S(G1)` doit rester atteignable par `L(A, G1)`.

Points clés du design :

- **Idempotent & auto-réparateur** : après un reboot du worker, iptables, routes et
  l'état local sont perdus ; le front renvoie la topologie complète à chaque *handshake*
  (un message par groupe concernant ce worker) → tout est réappliqué.
- **Aucune dépendance d'ordre** : les règles sont exprimées en **sous-réseaux**, pas par
  interface ; elles sont valides même si le bridge/la route du lab local n'existe pas encore.
- **Pas de changement dans `deleteLabInstance`** : la chaîne `shared_forward` est globale
  et partagée entre labs ; le front renvoie la topologie du groupe après chaque suppression.

## 4. Modifications concrètes (worker)

### 4.1 Bundle partagé — `remotelabz-message-bundle`

> Dépôt : `remotelabz/remotelabz-message-bundle`, chemin local `lib/remotelabz-message-bundle`
> (path repository `composer.json:88`). À pousser sur le dépôt **et** synchronisé dans
> `lib/` du worker **et** du front avant toute implémentation.

- **Nouveau** `Message/SecurityMessage.php` :

  ```php
  class SecurityMessage
  {
      private string $content; // JSON de la topologie d'un groupe
      private string $group;   // UUID du groupe = clé d'état côté worker

      public function __construct(string $content, string $group = '') { ... }
      // getters / setters
  }
  ```

- **Nouveau** `Tests/SecurityMessageTest.php` (format de `InstanceActionMessageTest`).

### 4.2 Nouveau handler — `src/MessageHandler/SecurityMessageHandler.php`

- Implémente `MessageHandlerInterface` (autoconfigure actif : `config/services.yaml:38`).
- Décode `content` (JSON), appelle `InstanceManager::updateSharedSecurity($group, $links)`.
- Même pattern que `InstanceActionMessageHandler`.

### 4.3 `src/Service/Instance/InstanceManager.php`

- **Nouvelle méthode** `updateSharedSecurity(string $groupUuid, array $links): void` :
  1. `$myIp = $this->params->get('app.worker.ip')` (paramètre déjà présent :
     `config/services.yaml:8` ; injection `$worker_ip: '%app.worker.ip%'` sur le constructeur
     selon le besoin, cf. `WorkerStartedEventSubscriber` — `services.yaml:74`) ;
  2. charger l'état `var/shared-security.json` (structure par groupe, cf. §5) ;
  3. **remplacer** l'entrée `[groupUuid]` par les liens reçus (suppression de l'entrée si
     `links` vide) → les autres groupes sont **préservés** ;
  4. calculer l'union des liens de tous les groupes filtrés localement
     (`a.workerIp == $myIp` ou `b.workerIp == $myIp`) ;
  5. `IPTables::create_chain('shared_forward')` + assurer le saut `FORWARD` (via `exists`/`append`) ;
  6. `IPTables::flush_chain('shared_forward')` puis append des règles de l'union :
     `Rule::create()->setSource($netA)->setDestination($netB)->setJump('ACCEPT')` (+ inverse) ;
  7. routes : comparer l'union désirée à celle de l'état →
     `IPTools::routeDelete` des obsolètes, `IPTools::routeAdd("net via ip")` des nouvelles
     (garde `IPTools::routeExists`), réécrire `var/shared-security.json` ;
  8. union locale vide → flush chaîne + purge de toutes les routes de l'état.
- **Inchangé** : `deleteLabInstance`, `Create_And_Secure_OVS`, chaînes `<br>_forward`.

### 4.4 `src/Bridge/Network/IPTables/Rule.php`

- Ajouter la **négation de destination** (`setDestination($cidr, $negate = false)` ou
  `setDestinationNot($cidr)`) → export `! -d <cidr>`. Nécessaire uniquement pour les
  règles MASQUERADE (§6), pas pour les règles de la chaîne `shared_forward`.

### 4.5 `bin/remotelabz-worker-network-up`

- Règle MASQUERADE (~`:151-164`) : ajouter `! -d $LAB_NETWORK` avant `-j MASQUERADE`
  → le trafic **lab ↔ lab** ne sera jamais masquéré (le trafic vers Internet l'est toujours).

### 4.6 `InstanceManager::connectToInternet` (`:2286`)

- Même ajout `! -d <LAB_NETWORK>` sur la règle
  `-s <labNet> -o <iface> MASQUERADE` (nécessite la négation de §4.4).

## 5. Format du payload (JSON) et état local

Message reçu (topologie d'**un** groupe) :

```json
{
  "group": "3f2b9c1e-....-....-....-............",
  "links": [
    {
      "a": {
        "uuid": "9a1c....-...",
        "network": "10.11.0.0/24",
        "workerIp": "192.168.11.132"
      },
      "b": {
        "uuid": "77bd....-...",
        "network": "10.11.1.0/24",
        "workerIp": "192.168.11.133"
      }
    }
  ]
}
```

- Liens **non orientés** : les deux directions d'`ACCEPT` sont générées par le worker.
- `group` = **clé d'état** (remplacement atomique de l'entrée du groupe).
- Broadcast à tous les workers ; chaque worker ne retient que les liens concernant sa propre IP.

État persistant `var/shared-security.json` :

```json
{
  "<uuid-groupe-G1>": { "links": [ { "a": {...}, "b": {...} } ] },
  "<uuid-groupe-G2>": { "links": [ { "a": {...}, "b": {...} } ] }
}
```

La chaîne `shared_forward` et les routes reflètent toujours la **union** de ces entrées —
les groupes coexistent sans s'écraser, et une instance de `L` dans `G2` n'entretient
aucune règle avec `S(G1)`.

## 6. Points d'attention

| Sujet | Risque | Traitement |
|---|---|---|
| **Multi-groupes** | Un message d'un groupe ne doit pas effacer les règles des autres groupes (ex. `G2` ne doit pas casser `S(G1) ↔ L(G1)`) | État local par groupe + rebuild sur **union** (§3, §4.3) |
| **MASQUERADE** (`network-up` + `connectToInternet`) | Le trafic inter-labs cross-worker sort par `ens34` (iface de data) → SNAT : le lab distant verrait l'IP du worker, pas celle du lab émetteur | Ajouter `! -d LAB_NETWORK` aux deux règles (§4.5, §4.6) |
| **Table 4 de `connectToInternet`** (`from/to 10.11.0.0/16 lookup 4`) | Détournerait le trafic inter-labs vers la route par défaut | Résolu naturellement : les routes de pairs sont en **/24** (plus spécifiques que le /16) → elles l'emportent |
| **rp_filter strict** (réception sur ens34) | Paquets depuis un réseau distant sans route symétrique rejetés | Routes ajoutées **symétriquement** sur les deux workers → OK ; à valider en test |
| **Déploiement du bundle** | Ancien worker : classe `SecurityMessage` inconnue → message en erreur | Ordre : bundle → workers → front |
| **Ordre des chaînes `FORWARD`** | — | Indifférent : `shared_forward` ne fait qu'`ACCEPT`, aucun DROP en amont |

## 7. Cycle de vie (rappel des déclencheurs côté front)

Chaque déclencheur renvoie la topologie **d'un groupe donné** ; le worker remplace
l'entrée de ce groupe et reconstruit l'union.

| Événement front | Effet topologie du groupe | Côté worker |
|---|---|---|
| Placement d'un lab (`LabLaunchRequestMessageHandler`, après `setWorkerIp`) | instance entre dans la topologie | remplacement d'entrée + rebuild union |
| Démarrage device (`InstanceManager::start()`) | idem (couvre restart après stop) | idempotent |
| Arrêt device (`InstanceManager::stop()`) | instance **sort** de la topologie | remplacement + purge des routes liées |
| Suppression (`InstanceStateMessageHandler`, `STATE_DELETED`) | topologie réduite | idem |
| Création/suppression d'une règle `LabShare` (`LabController::update`) | liens ajoutés/retirés selon les règles du groupe (message à liens vides → nettoyage) | remplacement d'entrée + rebuild union |
| Retrait d'un lab du groupe (`GroupController::removeLabAction`) | purge des règles concernées → topologie réduite | idem |
| Handshake worker (`WorkerHandshakeMessageHandler`) | renvoi complet des groupes concernant ce worker (un message par groupe) | **réapplique tout après reboot** |

## 8. Tests & vérification

1. **Unitaires** : `Tests/SecurityMessageTest` (bundle) ; logique de remplacement/union
   de l'état par groupe dans `updateSharedSecurity`.
2. **Manuel — 1 worker, 2 groupes** (isolation inter-groupes) :
   - règle de partage `(S, L, G1)` ; `G1` : prof lance **S**, utilisateur A rejoint **L**
     → `S(G1) ↔ L(A,G1)` ✅ ;
   - `G2` : utilisateurs instancient aussi **L** (sans règle `(S,L,G2)`) →
     `S(G1)` ↔ `L(G2)` **injoignable** ❌ ;
   - envoyer/actualiser la topologie de `G2` → vérifier que les règles de `G1` subsistent
     (`iptables -S shared_forward`).
3. **Manuel — 2 workers** :
   - groupe avec labs **A**, **B**, **C** répartis sur les 2 workers, règles `(A,B,G)` et `(A,C,G)` ;
   - vérifier : `A ↔ B` et `A ↔ C` joignables (ping), **pas** `B ↔ C` (aucune règle) ;
   - stop / start de B → connectivité coupée puis rétablie ;
   - suppression de la règle `(A,B,G)` → liens `A ↔ B` purgés, `A ↔ C` intacts ;
   - delete de B → topologie réduite, routes purgées (`var/shared-security.json`, `ip route show`) ;
   - reboot d'un worker → handshake → règles et routes restaurées ;
   - vérifier que le trafic inter-labs **n'est pas masqué** (les IPs visibles sont celles des labs).
4. **Vérifier le SQL des règles** : `iptables -S shared_forward` sur chaque worker.
