# Consentement 2026 — feuille de route

## Objectif

Étendre Dendrila Privacy au-delà d’une CMP classique sans dépendre d’un cloud tiers pour les preuves ou la synchronisation.

## Lot 1 — socle

- résolution multi-appareils pour les comptes WordPress authentifiés ;
- deux stratégies de contradiction : le choix du compte prévaut ou le choix de ce terminal prévaut ;
- information explicite lorsque la synchronisation est activée et rappel après authentification ;
- registre local de preuves de consentement avec chaîne d’intégrité HMAC ;
- pseudonymisation HMAC des adresses e-mail, sans adresse en clair persistée dans le registre ;
- export et effacement via les outils de confidentialité WordPress ;
- action WordPress publique pour les futurs adaptateurs e-mail.

Les fonctions susceptibles de modifier le comportement public restent désactivées par défaut.

## Lot 2 — adaptateurs e-mail

Priorité : MailPoet et FluentCRM, puis Newsletter, Mail Mint, Brevo et Mailchimp lorsque leur API publique permet une intégration fiable.

Pour chaque outil : détecter l’état réel du suivi d’ouverture, distinguer marketing et délivrabilité, enregistrer les consentements et retraits, modifier le réglage uniquement via une API publique vérifiable, et guider l’administrateur sinon.

## Lot 3 — centre de préférences e-mail

Ajouter un lien signé et temporaire permettant au destinataire de consulter ou retirer son choix relatif aux pixels sans créer de compte et sans exposer son adresse dans l’URL. Ce lien devra être distinct des liens de mesure de clics.

## Lot 4 — cycle de vie des preuves

Ajouter une durée de conservation configurable, un export CSV, un contrôle d’intégrité global, un import documenté et une stratégie de rotation du secret.

## Contraintes permanentes

- aucune télémétrie vers l’éditeur du plugin ;
- aucune synchronisation multi-appareils pour les visiteurs non authentifiés ;
- aucune adresse e-mail en clair dans le registre de preuve ;
- aucune IP ni user-agent stockés par défaut ;
- accepter, refuser et retirer ont la même portée multi-appareils ;
- le suivi individualisé des clics reste qualifié séparément des pixels d’ouverture ;
- avant chaque release WordPress.org, la description principale est revue pour rendre les fonctions visibles sans revendication juridique non démontrée.
