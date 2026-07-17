# Kadens

Webapp de **planification** d'entraînements sportifs (muscu, course/trail, vélo, natation, mobilité). Pas de tracking détaillé — Strava couvre déjà ce besoin. L'objectif est l'amont : bâtir une bibliothèque d'exercices, composer des séances, construire des plans multi-semaines réutilisables, les poser sur un calendrier, et comparer prévu vs réalisé.

Le détail complet des décisions d'architecture, du modèle de données et des phases de développement vit dans [`ROADMAP.md`](./ROADMAP.md) — c'est la référence à jour, ce README ne fait qu'orienter.

## Stack

- **Symfony 8.1** (full-stack, rendu serveur), PHP 8.4
- **Twig** + **Stimulus** + **Turbo** — pas de SPA, pas de framework front lourd
- **AssetMapper** pour les assets (pas de Webpack Encore)
- **Doctrine ORM** + **MariaDB 10.4** (même version en dev et en prod pour éviter tout écart)
- **Docker** pour l'environnement de dev uniquement — la prod mutualisée ne fait pas tourner de conteneurs
- Déploiement CI/CD (GitHub Actions) vers un hébergement mutualisé Infomaniak

## État du projet

Le socle Symfony est en place (Docker, MariaDB, CI/CD). Le développement fonctionnel suit les phases décrites dans [`ROADMAP.md`](./ROADMAP.md), en commençant par la bibliothèque d'exercices.

## Prérequis

- PHP 8.4 avec les extensions `ctype`, `iconv`, `pdo_mysql`
- [Composer](https://getcomposer.org/)
- Docker et Docker Compose (pour la base de données)
- Le [Symfony CLI](https://symfony.com/download) (recommandé)

## Installation

```bash
composer install
docker compose up -d
symfony console doctrine:migrations:migrate
```

Copier `.env` en `.env.local` si des valeurs locales doivent surcharger les valeurs par défaut (`DATABASE_URL` notamment).

## Lancer le projet

```bash
symfony server:start
```

Ou avec le serveur PHP intégré :

```bash
php -S 127.0.0.1:8000 -t public
```

Un service Mailpit est disponible en dev (`compose.override.yaml`) pour intercepter les mails envoyés par l'application, accessible sur le port `8025`.

## Tests

```bash
vendor/bin/phpunit
```

## Déploiement

Le déploiement est automatisé via [`.github/workflows/deploy.yml`](./.github/workflows/deploy.yml) : à chaque push sur `main`, le build et les tests tournent, puis un déploiement vers l'hébergement mutualisé Infomaniak attend une validation manuelle dans l'onglet Actions avant d'être exécuté (rsync + migrations + cache warmup côté serveur).
