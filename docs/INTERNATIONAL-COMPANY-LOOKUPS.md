# Registres d’entreprises internationaux

## Objectif

Dendrila Privacy doit pouvoir préremplir des informations publiques d’une organisation sans devenir dépendant d’un agrégateur commercial unique.

Le parcours est piloté par le pays choisi par l’administrateur. Aucun appel réseau ne part avant un clic explicite sur la recherche.

## Fournisseurs actifs

### France — DINUM

- fournisseur : API Recherche d’entreprises ;
- entrée : nom, SIREN ou SIRET ;
- sortie utilisée : nom, siège, SIREN/SIRET, TVA lorsque disponible, activité et statut ;
- accès : API publique sans clé ;
- réponse demandée en mode minimal afin d’éviter de récupérer des champs inutiles.

### Norvège — Brønnøysundregistrene

- fournisseur : Brønnøysund Register Centre, Enhetsregisteret Open Data API ;
- entrée : nom de structure ou numéro d’organisation à 9 chiffres ;
- sortie utilisée : nom, numéro d’organisation, adresse, forme juridique, activité et indicateurs publics de statut ;
- accès : API REST publique sans clé ;
- licence : Norwegian Licence for Open Government Data (NLOD) ;
- confidentialité : aucun endpoint de rôles/personnes n’est appelé.

### Union européenne / Irlande du Nord — VIES

- fournisseur : Commission européenne, VAT Information Exchange System ;
- entrée : pays + numéro de TVA ;
- usage : validation d’un numéro de TVA pour les échanges intra-UE ;
- sortie utilisée : validité, nom et adresse lorsque l’administration nationale les expose ;
- limite : VIES n’est pas un moteur de recherche par raison sociale et certains États ne renvoient pas le nom ou l’adresse.

## Contrat interne de résultat

Les fournisseurs renvoient à l’interface un format commun :

- `country`
- `name`
- `address`
- `vat`
- `registrationNumber`
- `registrationLabel`
- `registry`
- `legalForm`
- `activity`
- `entityType`
- `status`
- `source`

Les champs absents restent vides. Un fournisseur international ne doit jamais inventer une donnée pour remplir un champ français comme SIREN ou SIRET.

## Règles de confidentialité

1. Aucun appel au registre pendant l’installation, l’activation, un scan ou un cron.
2. L’administrateur choisit le pays et déclenche explicitement la recherche.
3. Seuls le pays et la requête nécessaire au fournisseur sont envoyés.
4. Les audits, contenus de pages, réponses de l’assistant et choix des visiteurs ne sont jamais joints.
5. Les réponses sont mises en cache localement pendant une durée courte afin de limiter les appels.
6. Chaque nouveau fournisseur externe doit être documenté dans `readme.txt` avant publication WordPress.org.

## Prochaines extensions

Ajouter un registre national uniquement après vérification de sa documentation officielle, de ses conditions d’accès et de la stabilité de son API. Une API nécessitant une clé devra conserver cette clé côté serveur WordPress et ne jamais l’exposer au JavaScript public.

Priorité aux registres officiels ou services publics. Éviter les agrégateurs commerciaux lorsqu’un registre public offre la même information.


## Sélection du fournisseur

- `FR` → recherche DINUM par nom, SIREN ou SIRET.
- `NO` → recherche Enhetsregisteret par nom ou organisasjonsnummer.
- autres codes proposés → VIES, avec un numéro de TVA obligatoire.
- un pays sans fournisseur vérifié ne doit pas apparaître comme « recherche automatique » : le formulaire reste manuel.

Le changement de résultat doit vider les métadonnées incompatibles du résultat précédent afin d’éviter qu’une forme juridique, une activité ou un registre d’un ancien choix soit conservé par erreur.
