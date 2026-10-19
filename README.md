# lwb-whmcs-wha — WhatsApp pour WHMCS, sans API officielle

Module WHMCS qui envoie vos notifications clients **sur WhatsApp, en plus des e-mails**, avec **votre
propre numéro** : factures avec PDF et lien de paiement, relances, confirmations de paiement,
activations de service, domaines, réponses aux tickets. Il envoie aussi des alertes internes à votre équipe.
Pas de compte Meta Business, pas d'API payante : une petite passerelle Node.js se connecte à WhatsApp
comme **WhatsApp Web** (appareil lié).

```
WHMCS (e-mails, cron) ──► file d'attente anti-ban ──► passerelle Node.js ──► WhatsApp ──► client
WHMCS (boîte de réception, tickets) ◄── cron ◄── passerelle Node.js ◄── WhatsApp ◄── client
```

> ⚠️ Automatiser WhatsApp Web n'est pas autorisé par les conditions d'utilisation de WhatsApp. Le module
> réduit fortement le risque de bannissement, sans pouvoir l'exclure. Utilisez un numéro dédié à
> l'entreprise, écrivez uniquement à vos clients, jamais de prospection de masse.

## Fonctionnalités

- **Suit vos e-mails WHMCS** (hook `EmailPreSend`) : chaque e-mail concerné déclenche son WhatsApp,
  y compris ceux envoyés par vos propres hooks (`SendEmail`). 18 notifications prêtes à l'emploi, au ton
  commercial et chaleureux, modifiables, avec variantes aléatoires `{Bonjour|Hello}`.
- **Factures** : PDF joint, lien de paiement sans connexion (FlexPay s'il est installé), montant restant,
  échéance en langage naturel (« demain », « dans 7 jours »), date de suspension calculée.
- **Liens courts suivis** sur votre domaine (`index.php?m=lwb_whmcs_wha&c=…`). Ils restent valables (le lien
  de paiement est recalculé au clic) et comptent les clics.
- **Revalidation avant envoi** : une facture payée entre-temps ne déclenche jamais de relance, un service
  réactivé n'envoie pas « suspendu », un domaine renouvelé n'envoie pas « expire ».
- **Fusion intelligente** : à la commande, un seul message (commande + paiement + bienvenue) au lieu de trois.
- **Alertes équipe** sur WhatsApp : paiement reçu, nouvelle commande, nouveau ticket, réponse d'un client,
  demande de résiliation.
- **Boîte de réception** : les messages WhatsApp de vos clients arrivent dans WHMCS, rattachés à la fiche
  client par le numéro. L'onglet permet de filtrer par client, nom ou numéro et de **répondre directement**,
  via la file d'envoi et ses protections anti-bannissement. Les médias sont signalés (`[Photo] légende`,
  `[Message vocal]`…) et le message cité est indiqué (« En réponse à : Rappel avant échéance »).
  En option (désactivée par défaut), les messages d'un client sont ajoutés à son ticket ouvert, ou lui ouvrent
  un ticket dans le département choisi.
- **Suivi** : distribué / lu (✓✓), clics, statistiques, factures payées dans les 7 jours suivant un WhatsApp.
- **Administration** : tableau de bord, liaison par QR code **ou par code à 8 caractères**, file
  d'attente avec actions, aperçu réel sur votre numéro, envoi manuel avec facture PDF, widget du tableau
  de bord WHMCS, encart dans la fiche client (derniers messages envoyés et reçus), alerte e-mail si WhatsApp
  se déconnecte.
- **Consentement** : réponse STOP / START, champs du profil client « Numéro WhatsApp » et
  « Pas de notifications WhatsApp », liste des désinscriptions.
- **Secours** : bascule possible vers WhatsApp Cloud API (officielle), Sent.dm ou une passerelle HTTP.

## Anti-bannissement

| Protection | Où |
|---|---|
| File d'attente, envoi progressif au cron, par priorité | module |
| Heures calmes dans le fuseau des clients (ex. 21 h – 8 h à Kinshasa) | module |
| Espacement minimal et plafond quotidien **par client** | module |
| Fusion des messages redondants, dédoublonnage sur 24 h | module |
| Durée de validité par notification (un rappel trop tardif est abandonné) | module |
| Délai aléatoire entre deux messages, simulation « en train d'écrire » | passerelle |
| Quotas par heure / jour, **rodage** d'un nouveau numéro | passerelle |
| Plafond quotidien de **nouveaux contacts** | passerelle |
| Respect des **limites renvoyées par WhatsApp** (plafond de nouvelles discussions, restriction temporaire) | passerelle |
| Vérification que le numéro a WhatsApp (mise en cache) | passerelle |
| Renvoi des messages à la demande du destinataire (pas de « En attente de ce message ») | passerelle |
| Lecture des messages reçus, présence hors ligne, reconnexion progressive | passerelle |
| Pause automatique (sans perte) si WhatsApp est déconnecté | les deux |

## Installation

### Prérequis
- WHMCS 8.x, PHP 7.4 ou plus récent (cURL, mbstring).
- Node.js 22.9 ou plus récent sur le même serveur que WHMCS, de préférence. Sous CloudLinux/cPanel :
  `/opt/alt/alt-nodejs22/root/usr/bin/node`.
- Un téléphone avec WhatsApp ou WhatsApp Business, sur un numéro dédié.
- Le cron WHMCS actif (toutes les 5 minutes).

### 1. Passerelle
```bash
cp -r gateway ~/whatsapp-gateway && cd ~/whatsapp-gateway && npm ci --omit=dev
```
```bash
cp .env.example .env
```
Dans `.env`, mettez une clé aléatoire dans `API_KEY` et un `PORT` libre :
```bash
node -e "console.log(require('crypto').randomBytes(24).toString('hex'))"
```
Démarrez la passerelle :
- **avec SSH** : `pm2 start ecosystem.config.cjs && pm2 save` ;
- **sans SSH** (cPanel) : à l'étape 2, renseignez le dossier et le binaire Node.js, et le cron WHMCS
  démarrera la passerelle (puis la redémarrera si elle s'arrête).

### 2. Module WHMCS
1. Copiez `modules/addons/lwb_whmcs_wha/` dans `modules/addons/` de WHMCS.
2. **Configuration ▸ Paramètres système ▸ Modules addon ▸ LWB WhatsApp** : activez, puis configurez :
   URL (`http://127.0.0.1:PORT`), clé API, dossier et binaire Node.js, fuseau horaire, numéros de l'équipe.
   Donnez l'accès à votre groupe d'administrateurs.
3. **Addons ▸ LWB WhatsApp ▸ Tableau de bord** : scannez le QR code (WhatsApp ▸ Appareils connectés), ou
   demandez un code de liaison si WHMCS est ouvert sur le même téléphone.
4. Onglet **Aperçu** : envoyez-vous une notification réelle (ex. une facture) pour vérifier le rendu.

### Mise à jour d'une version 3.x
1. Remplacez le dossier `modules/addons/lwb_whmcs_wha/` de WHMCS. Les nouvelles tables sont créées
   automatiquement (au prochain cron ou à l'ouverture du module).
2. Dans le dossier de la passerelle, remplacez `server.js`, `package.json` et `package-lock.json` (puis
   `npm ci --omit=dev` si le journal des versions indique de nouvelles dépendances), et redémarrez-la
   (`pm2 restart`, ou arrêtez le processus : le cron WHMCS la relance). La session WhatsApp est conservée.

### Mise à jour depuis `whatsapp_notify`
Activez `lwb_whmcs_wha` : il reprend les réglages, l'état des modèles et l'historique de l'ancien module.
Désactivez ensuite **WhatsApp Notify** (le tableau de bord vous le rappelle). La passerelle garde sa
session : remplacez simplement `server.js`, `package.json` et `package-lock.json`, puis redémarrez-la.

## Notifications

| Notification | Déclenchée par | Par défaut |
|---|---|---|
| Nouvelle facture (+ PDF) | Invoice Created | ✅ |
| Rappel avant échéance (J-7, J-1, réglable) | Invoice Payment Reminder | ✅ |
| Facture en retard / relances suivantes | First / Second Invoice Overdue Notice | ✅ / ❌ |
| Dernier rappel avant suspension | Third Invoice Overdue Notice | ✅ |
| Paiement reçu (+ reçu PDF) | Invoice Payment Confirmation | ✅ |
| Service activé | tout e-mail de bienvenue produit | ✅ |
| Service suspendu / réactivé | Service (Un)Suspension Notification | ✅ |
| Domaine bientôt expiré / expiré | Upcoming Domain Renewal / Expired Domain Notice | ✅ |
| Domaine enregistré / renouvelé | Domain Registration / Transfer Completed / Renewal Confirmation | ✅ |
| Bienvenue nouveau client | Client Signup Email | ✅ |
| Commande reçue (+ lien de paiement) | Order Confirmation | ✅ |
| Réponse à un ticket / ticket reçu | Support Ticket Reply / Opened | ✅ / ❌ |
| Avantages clients (marketing) | votre e-mail promotionnel | ❌ |
| Alertes équipe (5) | paiement, commande, ticket, réponse client, résiliation | ✅ (si numéros équipe) |

Principales variables : `{greeting}` (Bonjour/Bonsoir selon l'heure), `{first_name}`, `{invoice_id}`, `{total}`,
`{balance}`, `{due_when}`, `{suspend_when}`, `{pay_link}`, `{early_discount}`, `{service}`, `{domain}`,
`{renew_link}`, `{ticket_link}`, `{payment_methods}`, `{client_area}`. La liste complète s'affiche sous chaque modèle.

## Trucs et astuces

L'onglet **Astuces** du module en donne la liste complète. Les plus importantes :
- un **numéro local et dédié** (carte SIM du pays de vos clients), avec WhatsApp Business et un profil complet ;
- demandez aux clients d'**enregistrer votre numéro** (e-mails, factures) et **répondez vite** à leurs messages ;
- laissez le **rodage** se terminer avant d'augmenter les quotas, par paliers ;
- un message = une information utile + un seul lien ; gardez le pied « Répondez STOP » ;
- ouvrez WhatsApp sur le téléphone au moins tous les 14 jours (sinon l'appareil lié est déconnecté).

## Dépannage

| Symptôme | Solution |
|---|---|
| « Passerelle injoignable » | Vérifiez le dossier, le binaire Node.js et `gateway.log` ; le cron la redémarre |
| « Clé API invalide » | La clé du module doit être identique à `API_KEY` du `.env` |
| Messages qui restent en attente | Heures calmes, quota atteint, espacement par client, ou WhatsApp déconnecté (voir le tableau de bord) |
| « Plus d'actualité » | Normal : la situation a changé avant l'envoi (facture payée…) |
| « n'a pas de compte WhatsApp » | Corrigez le numéro du client ou renseignez son champ « Numéro WhatsApp » |
| Statut « Refusé (403) » | Le numéro est peut-être banni : vérifiez le téléphone, reliez un numéro de secours |
| Boîte de réception vide | Passerelle 3.1.0 ou plus requise (l'onglet le signale) ; les messages arrivent au cron suivant ou à l'ouverture de l'onglet |
| Message reçu « Numéro inconnu » | Le numéro ne correspond à aucun client : renseignez le téléphone ou le champ « Numéro WhatsApp » du client |

## Sécurité
- La passerelle écoute sur `127.0.0.1` par défaut et chaque appel exige la clé API.
- `gateway/data/` contient la session WhatsApp : protégez-le et sauvegardez-le (sinon, nouveau QR code).
- Les messages reçus restent 30 jours dans `gateway/data/inbox.json` (2 000 au plus), puis dans WHMCS selon
  la durée de conservation de l'historique. Dans un ticket, les chevrons sont neutralisés et les emojis
  retirés (base utf8) ; le message complet reste dans la boîte de réception.
- Les liens courts sont aléatoires et ne redirigent que vers votre WHMCS.

## Développement
```bash
php tests/run.php
```
```bash
node --check gateway/server.js
```
Chaque `push` est testé par GitHub Actions (PHP 7.4 à 8.3, passerelle). Une étiquette `vX.Y.Z` publie une
release avec le paquet prêt à installer.
