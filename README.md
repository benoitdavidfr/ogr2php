# Package Php de lecture de séries de données géographiques

Ce package est composée de 2 parties :

  1. les classes OgrInfo, Ogr2Php et Feature qui proposent de lire les objets géographiques d'une couche Ogr,
    chaque Feature est défini par un dictionnaire de champs et une géométrie.
    Ces classes nécessitent que le logiciel [GDAL/OGR](https://gdal.org/) soit installé.
    Elles utilisent les exécutables ogrinfo et ogr2ogr.
  2. un chargeur générique de couche Ogr dans MySQL qui utilise la première partie.
    Il utilise aussi [yamldoc](https://github.com/benoitdavidfr/yamldoc) pour stocker les définitions
    des sources de données géographiques.
    
Le package repose sur [geometry](https://github.com/benoitdavidfr/geometry) qui gère la géométrie WKT/GeoJSON.
