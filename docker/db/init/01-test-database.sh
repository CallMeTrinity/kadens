#!/bin/bash
# Base de test : `<MARIADB_DATABASE>_test`, celle que Doctrine vise en env de
# test (`dbname_suffix: '_test'`, config/packages/doctrine.yaml).
#
# Le conteneur ne crée que MARIADB_DATABASE, et MARIADB_USER n'a de droits que
# sur elle : sans ce script, un volume recréé laisse les tests sans base, et
# `doctrine:database:create --env=test` échoue faute de droits.
#
# Exécuté par l'entrypoint MariaDB au PREMIER démarrage seulement (répertoire de
# données vide). Sur un volume existant, il ne se relance pas. Le schéma reste à
# poser ensuite : `APP_ENV=test php bin/console doctrine:schema:create`.
#
# Exécutable et autonome (client `mariadb` sur la socket du serveur temporaire,
# root déjà sécurisé à ce stade) : l'entrypoint « source » un .sh non
# exécutable, mais le partage de fichiers de Docker Desktop sur macOS le voit
# exécutable quand même — se reposer sur `docker_process_sql`, qui n'existe
# qu'au sourcing, cassait l'init selon l'hôte.
set -eu

mariadb --protocol=socket -uroot -p"${MARIADB_ROOT_PASSWORD}" <<-EOSQL
	CREATE DATABASE IF NOT EXISTS \`${MARIADB_DATABASE}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
	GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}_test\`.* TO '${MARIADB_USER}'@'%';
EOSQL
