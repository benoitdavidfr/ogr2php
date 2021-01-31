# Package Php de lecture de séries de données géographiques

Ce package est composée de 2 parties :

  1. les classes OgrInfo, Ogr2Php et Feature qui proposent de lire les objets géographiques d'une couche Ogr,
    chaque Feature est défini par un dictionnaire de champs et une géométrie.
    Ces classes nécessitent que le logiciel [GDAL/OGR](https://gdal.org/) soit installé.
    Elles utilisent les exécutables ogrinfo et ogr2ogr.
  2. un chargeur générique de couches Ogr dans MySQL qui utilise la première partie.
    
Le package nécessite:

  - le [composant Yaml de Symfony](https://symfony.com/doc/current/components/yaml.html) à installer avec composer,
  - le [module geometry](https://github.com/benoitdavidfr/geometry) qui gère la géométrie WKT/GeoJSON,
  - la [classe SQL](https://github.com/benoitdavidfr/phplib/blob/master/sql.inc.php)
    du [module phplib](https://github.com/benoitdavidfr/phplib) qui simplifie l'interface avec MySQL.

### La classe OgrInfo
La classe OgrInfo exécute une commande orginfo sur un fichier OGR,
analyse le listage retourné et le fournit sous forme structurée.

Elle comporte les méthodes suivantes.

#### Méthodes
  
  - `__construct(string $path)` exécute une commande orginfo sur le fichier défini par $path
  - `info(): array` génère un array d'infos

### La classe Feature
La classe Feature implémente le concept de Feature de GeoJSON.

#### Méthodes
  
  - `__construct($param)` initialise un objet à partir d'un GeoJSON sous forme d'un string,
  - `properties(): array` retourne les propriétés du Feature
  - `geometry(): Geometry` retourne la géometrie du Feature sous la forme d'un objet Geometry
  - `geojson(): array` retourne un tableau Php qui encodé en JSON correspond au Feature GeoJSON
  - `__toString(): string` retourne la représentation GeoJSON du Feature

### La classe Ogr2Php
La classe Ogr2Php est un itérateur qui exécute une commande org2ogr sur un fichier OGR
et renvoie à chaque itération un Feature.

Outre les méthodes de l'[interface Iterator](http://php.net/manual/fr/class.iterator.php),
elle comporte les méthodes suivantes.

#### Méthodes
  
  - `__construct(string $path)` exécute une commande org2ogr sur le fichier défini par $path
  - `info(): array` génère un array d'infos

### Le script sqlloader.php
Le script sqlloader.php  est un chargeur de couches OGR dans une table MySQL.
Il s'éxécute en mode CLI et est auto-documenté.

Il utilise la classe Ogr2Php et le fichier datasets.yaml pour obtenir
les définitions des sources de données géographiques.  
Il nécessite la définition des paramètres de connexion MySQL dans le fichier mysqlparams.inc.php
pour lequel on peut s'inspirer de mysqlparams.inc.php.model

Une classe SqlLoader est définie pour rendre le code plus modulaire.
