# Journal des versions

## Non publié — 2026-10-25

**Module WHMCS**
- Remise pour paiement anticipé : les messages annoncent le **prix normal** de la facture, puis la remise comme
  une offre conditionnelle (« réglez-la au plus tard le … et ne payez que … ; passé cette date, le montant sera
  de … »), au lieu d'annoncer le prix remisé « déjà déduit ». À 3 jours ou moins de la date limite, le message
  indique « Plus que N jours » ou « Dernier jour ».

## Non publié — 2026-10-24

Les notifications partent au bon moment.

**Module WHMCS**
- Heures calmes : les messages attendus par le client juste après son action (paiement reçu, service
  réactivé ou activé, commande reçue, réponse à un ticket, bienvenue, domaine enregistré ou renouvelé) et les
  alertes équipe partent aussi la nuit. Les relances et rappels attendent toujours la fin des heures calmes.

**Passerelle**
- Ajout de la dépendance `link-preview-js` (aperçu des liens dans les messages ; Baileys l'importe sans la
  déclarer, d'où l'erreur « Cannot find package 'link-preview-js' »).

## 3.2.0 — 2026-10-19

Envoyer à un client une facture ou une notification précise, sans renvoyer l'e-mail WHMCS.

**Module WHMCS** (passerelle inchangée)
- Page d'une facture : encart WhatsApp avec le numéro du client, les derniers envois pour la facture et le
  bouton **« Envoyer sur WhatsApp »**. Il envoie tout de suite la notification adaptée au statut : impayée,
  « Nouvelle facture » ou « Rappel avant échéance » au choix (relances de retard si la facture est échue) ;
  payée, « Paiement reçu ». Compte rendu affiché au retour sur la facture.
- Onglet **Envoyer** : choix du client, puis **notification WHMCS** (modèle et ID de l'élément, avec les
  éléments du client proposés) ou **message libre** avec la **facture de votre choix** en PDF (remplace « la
  dernière impayée »). La saisie est conservée après un refus. Confirmation demandée pendant les heures calmes.
- Une notification envoyée à la main reprend le texte, les liens suivis et le PDF de l'envoi automatique,
  avec les données à jour. Elle est refusée si le client s'est désinscrit (STOP, profil, liste des
  désinscriptions), si l'élément appartient à un autre client ou si elle n'a plus lieu d'être. Elle annule la
  même notification automatique encore en attente, et un double clic ne la renvoie pas.
- Compte rendu exact des envois manuels : un message retenu par un quota de la passerelle ou une déconnexion
  est signalé « en file d'attente » (il part au cron suivant) au lieu de « Échec ».
- Onglet Messages : étiquette « manuel » sur les notifications envoyées à la main.

## 3.1.0 — 2026-10-19

Les réponses des clients ne restent plus sur le téléphone : elles arrivent dans WHMCS.

**Module WHMCS**
- Onglet **Boîte de réception** : messages reçus des clients, filtre par client, nom ou numéro, lien vers la
  fiche client, message cité (« En réponse à : … »), **réponse rapide** envoyée par la file habituelle
  (quotas de la passerelle, STOP, simulation de frappe). Badge des messages sans réponse des dernières 24 h.
- Messages rapatriés à chaque cron (et à l'ouverture de l'onglet) dans la table `mod_lwbwha_inbox`,
  rattachés au client par son numéro, y compris le champ « Numéro WhatsApp ».
- Option **tickets** (désactivée par défaut) : les messages d'un client sont ajoutés à son ticket ouvert
  (celui de la notification citée en priorité), sinon un ticket est ouvert dans le département choisi, sans
  e-mail au client. Messages reçus ensemble regroupés en une seule réponse ; emojis retirés et chevrons
  neutralisés dans le ticket.
- Fiche client : les 5 derniers messages reçus. Tableau de bord et widget : messages reçus aujourd'hui.
- Un message envoyé depuis l'onglet Envoyer marque aussi comme répondus les messages de ce numéro.
- Correction : un numéro de l'équipe ou un numéro d'aperçu n'est plus pris pour celui d'un client (alertes
  équipe, désinscriptions).
- Purge des messages reçus selon la durée de conservation de l'historique.

**Passerelle** (dépendances inchangées)
- `GET /inbox?since=<ms>` : messages reçus, gardés 30 jours (2 000 au plus) dans `data/inbox.json`.
- Médias signalés dans le texte : `[Photo] légende`, `[Message vocal]`, `[Document : nom]`, `[Position]`…
- Les messages arrivés pendant une coupure de la passerelle sont traités à la reconnexion (STOP compris).
- STOP / START reconnus aussi dans les discussions éphémères ; numéro retrouvé pour les discussions
  adressées par LID.

## 3.0.0 — 2026-10-19

Nouveau nom : **lwb-whmcs-wha** (module WHMCS `lwb_whmcs_wha`), code réorganisé en classes (`LwbWha\…`).

**Module WHMCS**
- File d'attente et journal unifiés ; priorités ; durée de validité par notification.
- Revalidation juste avant l'envoi : facture payée, service réactivé, domaine renouvelé, client fermé.
- Fusion : la confirmation de commande remplace « nouvelle facture » et « bienvenue » arrivés ensemble.
- Espacement minimal et plafond quotidien par client ; heures calmes dans le fuseau des clients.
- Messages recalculés à l'envoi (montants, remise, liens toujours à jour).
- Liens courts suivis sur le domaine WHMCS, valables à vie, avec comptage des clics.
- Nouvelles variables : `{greeting}`, `{due_when}`, `{suspend_when}`, `{payment_methods}`, `{order_items}`, `{welcome_line}`…
- Alertes équipe sur WhatsApp : paiement, commande, nouveau ticket, réponse client, résiliation.
- Statuts distribué / lu, statistiques (lecture, clics, factures payées après un WhatsApp).
- Tableau de bord : santé, quotas, limites WhatsApp, liaison par QR code ou code à 8 caractères.
- Onglets Messages (filtres, envoyer maintenant, annuler, réessayer), Désinscriptions, Astuces.
- Widget du tableau de bord WHMCS, encart WhatsApp dans la fiche client.
- Alerte e-mail aux administrateurs si WhatsApp reste déconnecté plus de 15 minutes.
- Champs du profil client « Numéro WhatsApp » et « Pas de notifications WhatsApp ».
- Bouton WhatsApp optionnel dans l'espace client.
- Modèle « Avantages clients » qui suit l'e-mail promotionnel (désactivé par défaut).
- Une passerelle déconnectée met la file en pause sans consommer de tentatives.
- Migration automatique depuis `whatsapp_notify` (réglages, modèles, historique).
- Tests automatiques et intégration continue GitHub Actions.

**Passerelle**
- Respect des limites renvoyées par WhatsApp (plafond de nouvelles discussions, restriction temporaire).
- Plafond quotidien de nouveaux contacts, réduit pendant le rodage.
- `getMessage` : les renvois demandés par le destinataire fonctionnent (fin des « En attente de ce message »).
- Statuts de distribution / lecture (`POST /statuses`), désinscriptions (`GET/POST /optouts`).
- Liaison par code (`POST /pair`), reconnexion (`POST /reconnect`).
- Cache des vérifications de numéros, pas de synchronisation de l'historique, groupes et diffusions ignorés.
- Reconnexion progressive, arrêt des tentatives répétées en cas de bannissement (403).
- Verrou d'instance unique, arrêt propre, écritures disque groupées.

## 2.0.0 — 2026-10-19
- Notifications déclenchées par les e-mails WHMCS, liens de paiement FlexPay, PDF des factures,
  remise pour paiement anticipé, messages commerciaux et amicaux, aperçu réel, redémarrage automatique.

## 1.x — 2026-09 / 2026-10
- Module `whatsapp_notify` : fournisseurs Sent.dm / Cloud API / HTTP, puis passerelle WhatsApp Web
  auto-hébergée avec file d'attente, quotas, heures calmes et désinscription STOP.
