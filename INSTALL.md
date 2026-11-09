# Installation et configuration

Cette procédure installe le plugin WordPress et le script distant. Les valeurs de chemins, d’URL et de capacités doivent être adaptées à votre hébergement. Aucun secret réel n’est fourni dans les fichiers du projet.

## 1. Générer le secret partagé

Générez au moins 32 octets aléatoires sur une machine de confiance, puis transmettez la valeur aux deux serveurs par un canal privé :

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Ne placez jamais cette valeur dans le plugin, une page WordPress, un dépôt, une réponse HTTP ou un répertoire public. Ne journalisez pas les URL signées complètes.

## 2. Installer WordPress

Copiez le dossier `wp-files-provider` dans `wp-content/plugins/`, puis activez **WP Files Provider** dans l’administration WordPress.

Dans **Réglages → WP Files Provider** :

- indiquez l’URL HTTPS complète du script distant, par exemple `https://fichiers.example.test/files-provider.php` ;
- choisissez la durée du lien entre 30 et 600 secondes (120 par défaut) ;
- cochez les rôles qui peuvent télécharger. Le plugin leur attribue la capability dédiée `download_private_files` et la retire des rôles décochés. Aucun rôle n’est autorisé par défaut.

Le secret doit être configuré en privé dans `wp-config.php` ou dans l’environnement PHP. Exemple utilisant une variable d’environnement :

```php
define('WPFP_SHARED_SECRET', getenv('WPFP_SHARED_SECRET'));
```

Configurez cette variable dans l’environnement PHP-FPM de WordPress avec la valeur partagée. Si votre hébergeur ne fournit pas de variable d’environnement, mettez la valeur directement dans le `wp-config.php` privé. Vous pouvez aussi définir `WPFP_LINK_TTL` dans l’environnement ou `wp-config.php` pour surcharger la durée réglée dans l’administration ; le réglage reste borné à 30–600 secondes.

Ajoutez un lien dans un contenu WordPress :

```text
[wpfp_download path="rapports/rapport annuel.pdf" label="Télécharger le rapport"]
```

Le shortcode crée un lien protégé par nonce WordPress. À chaque demande, le plugin vérifie également que l’utilisateur est connecté et possède `download_private_files`. Le nonce ne remplace pas ces contrôles d’accès.

## 3. Installer le script et sa configuration privée

Copiez `litespeed/files-provider.php` sur le serveur LiteSpeed. Configurez l’URL HTTPS pour qu’elle exécute ce script. Si possible, installez le fichier du script hors de la racine publique et exposez uniquement le point d’accès PHP attendu.

Créez `/etc/wp-files-provider/config.php` **hors de tout répertoire public**, lisible par le compte PHP mais non modifiable par le processus web si possible :

```php
<?php
return [
    'secret' => getenv('WPFP_SHARED_SECRET'),
    'document_root' => '/srv/documents-prives',
    'clock_tolerance' => 30,
    'max_ttl' => 600,
    'max_bytes' => 800000000,
    'transfer_mode' => 'php',
];
```

Configurez `WPFP_SHARED_SECRET` dans l’environnement PHP-FPM LiteSpeed avec le même secret que pour WordPress. Si l’environnement ne peut pas transmettre cette variable, placez le secret directement dans ce fichier privé. La valeur `document_root` est la racine physique fixe des documents, hors accès public direct. Le compte PHP doit pouvoir lire ces fichiers.

La limite `max_bytes` est en octets : `800000000` correspond à 800 Mo décimaux (800 × 1 000 000 octets). Réglez aussi les limites de temps et de sortie de PHP-FPM selon la taille et le débit attendus. Le script lit et envoie le fichier par blocs sans le charger entièrement en mémoire.

Le chemin de configuration par défaut est `/etc/wp-files-provider/config.php`. Pour en utiliser un autre, définissez `WPFP_CONFIG` dans l’environnement PHP-FPM. Le script n’accepte jamais un chemin système venant de la requête : WordPress transmet un chemin relatif encodé et signé.

## 4. Distribution du fichier et protection des URL directes

### Mode recommandé ici : flux PHP

Gardez `transfer_mode` à `php` lorsque la racine des documents est hors de toute URI web. Le script valide la signature, refuse les chemins absolus, segments `..`, séparateurs ambigus, encodages `%`, caractères de contrôle et liens symboliques sortant de la racine, puis vérifie que la cible est un fichier régulier. Il renvoie `Content-Disposition: attachment` avec un nom échappé, et les réponses sont privées et non mises en cache.

Le flux PHP occupe un processus PHP pendant toute la durée du téléchargement. Cela peut limiter les gros transferts ou les téléchargements concurrents. La taille maximale est de 800 Mo par défaut, configurable dans le fichier privé.

### Mode LiteSpeed accéléré, facultatif

LiteSpeed documente `X-LiteSpeed-Location` comme redirection interne vers une URI locale et recommande de bloquer l’accès direct avec `%{ORG_REQ_URI}` dans une règle de réécriture. Consultez la [documentation LiteSpeed sur les redirections internes](https://docs.litespeedtech.com/lsws/redirect/).

Ce mode nécessite que la racine configurée soit également mappée à une URI interne du virtual host. Si la racine est véritablement hors du document root sans contexte ou alias contrôlé, ce mode n’est pas utilisable : gardez `php`.

Exemple : si `document_root` est `/srv/site/public/private-files` et que ce dossier correspond à `/private-files/`, ajoutez une règle LiteSpeed autorisée au niveau du virtual host ou dans un `.htaccess` actif :

```apache
RewriteEngine On
RewriteCond %{ORG_REQ_URI} ^/private-files/
RewriteRule ^private-files/ - [F,L]
```

Cette règle doit refuser une requête directe au fichier mais permettre la redirection interne émise par le script autorisé. Après avoir confirmé ce comportement sur votre instance, configurez :

```php
'document_root' => '/srv/site/public/private-files',
'transfer_mode' => 'litespeed',
'private_uri_prefix' => '/private-files/',
```

Le script construit l’URI interne depuis le chemin résolu sous la racine. Ne considérez jamais un nom d’URI difficile à deviner comme une protection. La compatibilité générale de LiteSpeed est documentée, mais le blocage effectif et le fonctionnement de cette configuration précise doivent être testés par vous sur le serveur. Testez une URL directe (elle doit renvoyer 403) et un téléchargement signé (il doit servir le fichier). Si l’un échoue, repassez en mode `php`.

## 5. HTTPS, caches et journaux

- Utilisez HTTPS du navigateur vers WordPress et de WordPress vers le serveur de fichiers. Le plugin refuse une URL distante sans schéma HTTPS.
- Excluez `/wp-admin/admin-post.php` des caches WordPress, LiteSpeed Cache, proxy et CDN. Si possible, ajoutez aussi un contournement conditionnel lorsque `action=wpfp_download`.
- Excluez l’URL du script distant des caches. Le plugin et le script émettent `private, no-store`, mais les caches/CDN doivent aussi être configurés pour les respecter ou contourner ces URL.
- Vérifiez les formats des journaux LiteSpeed, proxy et CDN : certains enregistrent la chaîne de requête qui contient le jeton porteur. Configurez les journaux pour omettre la chaîne de requête du point d’accès, ou excluez cette route des journaux détaillés.
- Vérifiez que les deux serveurs partagent le secret et que leurs horloges sont synchronisées. La tolérance par défaut est de 30 secondes ; `clock_tolerance` est configurable de 0 à 300 secondes.

## 6. Tests et limites

Exécutez localement :

```sh
php tests/wordpress-access.php
php tests/handler-security.php
```

Ces tests couvrent les utilisateurs non connectés, la capability insuffisante, signature altérée, expiration, chemin valide, traversée, lien symbolique hors racine, fichier absent et limite de taille. Ils ne vérifient pas votre configuration LiteSpeed, vos caches, votre certificat TLS ni vos règles d’hébergement : ces contrôles doivent être faits après installation.

Les URL signées sont des jetons porteurs partageables jusqu’à expiration. Cette implémentation ne prétend pas fournir un lien à usage unique. Les chemins contenant `%`, `\\`, `:`, des segments vides ou des caractères de contrôle sont volontairement refusés pour éviter les encodages ambigus. Les noms de fichiers Unicode sont acceptés et échappés dans `Content-Disposition`.
