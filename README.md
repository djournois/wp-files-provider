# WP Files Provider

Ce projet fournit un plugin WordPress et un point d’accès PHP pour distribuer des fichiers situés sur un serveur LiteSpeed distinct. WordPress vérifie la connexion et une capability dédiée avant de créer une URL HMAC temporaire. Le script distant vérifie cette signature et limite l’accès à une racine physique configurée hors de WordPress.

L’installation sur les deux serveurs est décrite dans [INSTALL.md](INSTALL.md). Les fichiers sont destinés à une installation manuelle ; aucune configuration d’hébergement n’est présumée ou vérifiée par ce projet.

Le mode initial est le flux PHP, adapté à une racine qui n’a pas d’URI web directe. Le script peut utiliser `X-LiteSpeed-Location` si vous exposez la racine par une URI interne LiteSpeed, bloquez l’accès direct et vérifiez le fonctionnement sur votre serveur. Lien signé = jeton porteur, partageable jusqu’à son expiration. Il n’est pas à usage unique.

## Exemple de lien dans WordPress

Placez ceci dans une page ou un article :

```text
[wpfp_download path="rapports/rapport annuel.pdf" label="Télécharger le rapport"]
```

Le chemin est relatif à la racine de documents configurée sur le serveur. Aucun catalogue exhaustif n’est requis.

## Vérifications locales

```sh
php -l wp-files-provider/wp-files-provider.php
php -l litespeed/files-provider.php
php tests/wordpress-access.php
php tests/handler-security.php
```
