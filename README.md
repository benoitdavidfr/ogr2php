# Package Php de lecture de séries de données géographiques

Ce package est composée de 2 parties :

  1. les classes OgrInfo, Ogr2Php et Feature qui proposent de lire les objets géographiques d'une couche Ogr,
    chaque Feature est défini par un dictionnaire de champs et une géométrie.
    Ces classes nécessitent que le logiciel [GDAL/OGR](https://gdal.org/) soit installé.
    Elles utilisent les exécutables ogrinfo et ogr2ogr.
  2. un chargeur générique de couches Ogr dans MySQL qui utilise la première partie.
    Il utilise aussi [yamldoc](https://github.com/benoitdavidfr/yamldoc) pour stocker les définitions
    des sources de données géographiques.  
    Il nécessite la définition des paramètres de connexion MySQL dans le fichier mysqlparams.inc.php
    pour lequel on peut s'inspirer de mysqlparams.inc.php.model
    
Le package s'appuie sur le [module geometry](https://github.com/benoitdavidfr/geometry)
qui gère la géométrie WKT/GeoJSON,
et sur la [classe MySQL](https://github.com/benoitdavidfr/phplib/blob/master/openmysql.inc.php)
du [module phplib](https://github.com/benoitdavidfr/phplib) qui simplifie l'interface avec MySQL.
