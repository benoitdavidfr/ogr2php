<?php
/*PhpDoc:
name: sqlloader2.inc.php
title: sqlloader2.inc.php - module V2 générique de chargement d'un produit dans une base MySQL
includes: [ ogr2php.inc.php, '../geom2d/geom2d.inc.php', '../phplib/srvr_name.inc.php' ]
functions:
doc: |
  La fonction sqlloader() met en oeuvre un chargeur SQL en fonction de paramètres du produit à charger
  Utilisation systématique du type Geometry
  Définition de la variable $sql_reserved_words contenant la liste des mots-clés réservés par SQL à ne pas utiliser comme 
  attribut.
  
journal: |
  31/7/2018
    passage en V2
  24/12/2016
    ajout d'un filtre sur la géométrie dans insert_into()
  22/12/2016
    modification du nom des colonnes des tables lorsqu'il correspond à un mot-clé SQL
  19/12/2016
    chgt de l'interface de sqlloader pour la définition des datasets
    chgt du type de géométrie à Geometry
  18/12/2016
    correction d'un bug lorsqu'il n'y a qu'un seul jeu
  17/12/2016
    sqlloader renvoie ogrinfo pour les actions info, create_table et load_table
  10/12/2016
    correction d'un bug
  9/12/2016
    ajout temporaire de la commande missing très spécifique à geoapi.fr/bdv
    il faut améliorer sa fiabilité et sa généricité
  7-8/12/2016
    adaptation à geoapi.fr/bdv
    Je constate sur geoapi.alwaysdata.net que MySQL modifie le type géométrique défini pour le remplacer par geometry
    Il est donc plus simple d'utiliser systématiquement le type Geometry plutot que MultiPolygon
  4-6/12/2016
    première version
*/
require_once __DIR__.'/ogr2php.inc.php';
require_once __DIR__.'/../geom2d/geom2d.inc.php';
