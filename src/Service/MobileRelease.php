<?php

namespace App\Service;

/**
 * La version publiée de l'app Android, déclarée côté serveur (KL-43).
 *
 * ## Pourquoi le serveur déclare, au lieu d'aller lire
 *
 * Le binaire vit dans une GitHub Release, le dépôt TNTStore ne fait que la
 * référencer (il n'héberge rien). Aller interroger l'un ou l'autre à chaque
 * appel ferait dépendre `GET /api/app-version` d'un tiers, alors que c'est
 * précisément l'endpoint qui doit répondre pour que l'app sache si elle a le
 * droit de synchroniser. Un mutualisé sans réseau sortant, une API GitHub qui
 * limite, et le garde-fou tombe en même temps que ce qu'il protège.
 *
 * Ces valeurs sont donc **déclarées** dans `config/services.yaml` et versionnées
 * : publier une version, c'est pousser un tag côté mobile puis bouger ces deux
 * lignes ici. Le prix est un oubli possible ; il se paie en « pas de bandeau de
 * mise à jour », jamais en synchronisation cassée.
 *
 * ## Zéro veut dire « rien de publié »
 *
 * `versionCode = 0` est l'état du jour : le workflow de build (KL-41) n'a encore
 * produit aucune release. C'est l'élément neutre des deux comparaisons du
 * client — rien n'est jamais plus récent que soi, rien n'est jamais en dessous du
 * minimum — donc l'app ne propose rien et ne bloque personne tant que la valeur
 * n'a pas bougé. Un `1` de complaisance, lui, aurait publié un lien d'APK en 404.
 */
final class MobileRelease
{
    public function __construct(
        private readonly int $versionCode,
        private readonly string $versionName,
        private readonly int $minimumVersionCode,
        private readonly string $repository,
        private readonly string $storeUrl,
        private readonly string $storeFingerprint,
    ) {
    }

    /** Y a-t-il quelque chose à installer ? Faux tant qu'aucune release n'existe. */
    public function isPublished(): bool
    {
        return $this->versionCode > 0;
    }

    /** Le numéro de build Android de la dernière version publiée. Ce qui se compare. */
    public function versionCode(): int
    {
        return $this->versionCode;
    }

    /** Le numéro lisible, sans le `v` du tag. Ce qui s'affiche. */
    public function versionName(): string
    {
        return $this->versionName;
    }

    /**
     * En dessous, l'app **refuse de continuer**. C'est la seule porte de sortie
     * prévue si le format de synchronisation change : un client qui écrirait un
     * document que le serveur ne sait plus relire vaut mieux arrêté que rejoué.
     * Elle ne monte donc **que** le jour où le contrat change, jamais pour
     * pousser une mise à jour de confort — c'est à ça que sert le bandeau.
     */
    public function minimumVersionCode(): int
    {
        return $this->minimumVersionCode;
    }

    /** L'URL du dépôt TNTStore, telle qu'elle se colle dans le store et telle que le QR l'encode. */
    public function storeUrl(): string
    {
        return $this->storeUrl;
    }

    /** L'empreinte de la clé qui signe l'index du dépôt (KL-40), à recouper à la main. */
    public function storeFingerprint(): string
    {
        return $this->storeFingerprint;
    }

    /**
     * L'APK en téléchargement direct, le secours quand le store ne veut pas.
     *
     * Dérivé, jamais déclaré : le nom du fichier est celui que compose
     * `.github/workflows/build.yml` (`kadens-<versionName>-<versionCode>.apk`) et
     * le tag est `v<versionName>`. Deux numéros suffisent donc à l'écrire, et le
     * lien ne peut pas désigner une autre version que celle annoncée juste à
     * côté. Contrepartie : renommer l'APK côté workflow casse ce lien, et ça se
     * voit en 404 — d'où la mention explicite du fichier à ne pas toucher seul.
     */
    public function apkUrl(): ?string
    {
        if (!$this->isPublished()) {
            return null;
        }

        return \sprintf(
            'https://github.com/%s/releases/download/v%s/kadens-%s-%d.apk',
            $this->repository,
            $this->versionName,
            $this->versionName,
            $this->versionCode,
        );
    }

    /** La page de la release : les notes, et le `mapping.txt` de R8 qui voyage avec l'APK. */
    public function releaseUrl(): ?string
    {
        if (!$this->isPublished()) {
            return null;
        }

        return \sprintf('https://github.com/%s/releases/tag/v%s', $this->repository, $this->versionName);
    }
}
