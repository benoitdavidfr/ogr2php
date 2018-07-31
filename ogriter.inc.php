<?php
/*PhpDoc:
name:  ogriter.inc.php
title: ogriter.inc.php - Itérateur sur les objets OGR - correction d'un bug le 15/7/2016
includes: [ featurewkt.inc.php, ogr2php.inc.php ]
classes:
doc: |
  La classe OgrIter est utilisée par la classe Ogr2Php comme itérateur sur les objets (features) contenus dans le fichier
journal: |
  31/7/2018
    remplacement de l'inclusion de featurewkt.inc.php par feature.inc.php
  6/12/2016
    correction d'un bug dans readOneFeature()
  16/7/2016
  - utilisation de featurewkt.inc.php à la place de feature.inc.php afin d'optimiser les traitements
  - autorise un WKT vide
  15/7/2016
  - correction d'un bug, le dernier objet n'est pas lu
  5/6/2016
  - ajout de MULTILINESTRING
  1/6/2016
  - modif par l'utilisation de feature.inc.php et geom2d.inc.php
  29/5/2016
  - première version
*/
require_once __DIR__.'/feature.inc.php';
/*PhpDoc: classes
name:  Class OgrIter
title: Class OgrIter implements Iterator - Itérateur sur les objets OGR
methods:
doc: |
  Exécute une commande orginfo sur une fichier TAB/SHP et retourne les features.
  Fonctionne comme un itérateur.
  En cas de code à améliorer, une exception est générée.
*/

Class OgrIter implements Iterator {
  private $ogr2php; // Conserve les infos fournies à la création de l'objet
  private $file=null; // handle sur le pipe en sortie de la comande ogrinfo
  private $buff=null; // buffer courant
  private $cfeature=null; // le feature courant lu par readOneFeature()
  private $cfeatureId=null; // l'id du feature courant lu par readOneFeature()
  
/*PhpDoc: methods
name:  __construct
title: function __construct($ogr2php) - création de l'itérateur
*/
  function __construct($ogr2php) {
    $this->ogr2php = $ogr2php->info();
  }
  
/*PhpDoc: methods
name:  readOneFeature
title: private function readOneFeature() - Lit un Feature dans le fichier et le copie dans $this->cfeature
doc: |
  En fin de fichier, $this->cfeature = null;
*/
  private function readOneFeature() {
//    echo "readOneFeature\n";
    $this->cfeatureId = null;
    $this->cfeature = null;
    $buff = $this->buff;
    while ($this->buff = fgets($this->file)) {
//      echo $this->buff;
      if (preg_match('!^OGRFeature\(!', $this->buff))
        break;
      $buff .= $this->buff;
    }
// Fin de fichier atteinte
    if (!$buff)
      return;
    $pattern = '!^'
              .'OGRFeature\([^)]+\):(\d+)\s*'
              .'(([^ ]* \((String|Integer|Real|Date)\) = [^\n\r]*\s*)*)'
              .'(Style = [^\n\r]*\s*)?'
              .'((POINT|LINESTRING|POLYGON|MULTILINESTRING|MULTIPOLYGON) ([-\(\)0-9\., ]+))?\s*'
              .'!';
    if (!preg_match($pattern, $buff, $matches)) {
//      echo "Erreur ligne ".__LINE__.", no match sur:\n**$buff**\n";
      throw new Exception("Erreur script ".__FILE__.", ligne ".__LINE__.", no match");
    }
//    global $bdtopo_debug;
//    if ($bdtopo_debug) { echo "matches="; print_r($matches); }
    $featureId = $matches[1];
    $fieldstr = $matches[2];
    $geomstr = $matches[6];
    $this->cfeatureId = $featureId;
    $patfields = '!^([^ ]*) \((String|Integer|Real|Date)\) = ([^\n\r]*)\s*!';
    while (preg_match($patfields, $fieldstr, $matches)) {
//      echo "matches="; print_r($matches);
      switch ($this->ogr2php['encoding']) {
        case 'ISO-8859-1' :
          $fields[$matches[1]] = utf8_encode($matches[3]); break;
        case 'UTF-8' :
        case null :
          $fields[$matches[1]] = $matches[3]; break;
        default :
          throw new Exception("Erreur ligne ".__LINE__.", encoding '$this->ogr2php[encoding]' inconnu\n");
      }
      $fieldstr = preg_replace($patfields, '', $fieldstr, 1);
    }
    if (!preg_match('!^\s*$!', $fieldstr))
      throw new Exception("Erreur ligne ".__LINE__.", reste fields:\n$fieldstr\n");
//    echo "fields="; print_r($fields);
    $this->cfeature = new Feature($fields, $geomstr);
  }
  
/*PhpDoc: methods
name:  rewind
title: function rewind() - Ouvre le pipe, lit l'en-tête et le premier feature
*/
  function rewind() {
    $ogr = $this->ogr2php;
//    echo "info="; print_r($ogr);
    if (isset($ogr['error']))
      throw new Exception("Erreur $ogr[error]\n");
//    $ogrinfo = (getenv('os') ? "c:\\\"Program files\"\\FWTools2.4.7\\bin\\ogrinfo.exe" : 'ogrinfo');
    $ogrinfo = (getenv('os') ? "\\usbgis\\apps\\FWTools\\bin\\ogrinfo.exe" : 'ogrinfo');
    $cmde = "$ogrinfo -al $ogr[filename] $ogr[layername]";
    $this->file = popen($cmde, 'r');
    if (!$this->file)
      throw new Exception("Erreur d'ouverture de $cmde\n");
    while ($this->buff = fgets($this->file)) {
//      echo $this->buff;
      if (preg_match('!^OGRFeature\(!', $this->buff)) {
        $this->readOneFeature();
        return;
      }
    }
  }
  
/*PhpDoc: methods
name:  valid
title: function valid() - valid ssi buff est sur un début d' OGRFeature
*/
  function valid() { return ($this->cfeature <> null); }
  
/*PhpDoc: methods
name:  current
title: function current() - renvoie le feature courant
*/
  function current() { return $this->cfeature; }
  
/*PhpDoc: methods
name:  next
title: function next() - Lit le feature suivant
*/
  function next() { $this->readOneFeature(); }
  
/*PhpDoc: methods
name:  key
title: function key() - renvoie la celf courante
*/
  function key() { return $this->cfeatureId; }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;
echo <<<EOT
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><title>ogriter</title></head><body>\n
EOT;


require_once 'ogr2php.inc.php';

if (!isset($_GET['path'])) {
  if (getenv('os')) {
    $route500 = 'P:/geodata-met/Route500-ED151';
    $bdcarthage = 'P:/geodata-met/BDCARTHAGE';
    $geobases = 'P:/xampp/htdocs/www/geobases';
  } else {
    $route500 = '/home/bdavid/geodata/ROUTE500_2-0__SHP_LAMB93_FXX_2015-08-01/ROUTE500/'
               .'1_DONNEES_LIVRAISON_2015/R500_2-0_SHP_LAMB93_FR-ED151';
    $bdcarthage = '/home/bdavid/geodata/BDCARTHAGE';
    $geobases = '/home/bdavid/www/geobases';
  }
  foreach ([
    'Route500 - COMMUNE' => "$route500/ADMINISTRATIF/COMMUNE.shp",
    'Route500 - LIMITE_ADMINISTRATIVE' => "$route500/ADMINISTRATIF/LIMITE_ADMINISTRATIVE.shp",
    'Route500 - TRONCON_ROUTE' => "$route500/RESEAU_ROUTIER/TRONCON_ROUTE.shp",
    'Route500 - NOEUD_ROUTIER' => "$route500/RESEAU_ROUTIER/NOEUD_ROUTIER.shp",
    'Route500 - COMMUNICATION_RESTREINTE' => "$route500/RESEAU_ROUTIER/COMMUNICATION_RESTREINTE.shp",
    'Route500 - NOEUD_COMMUNE' => "$route500/RESEAU_ROUTIER/NOEUD_COMMUNE.shp",
    'Route500 - AERODROME' => "$route500/RESEAU_ROUTIER/AERODROME.shp",
    'Route500 - AERODROME' => "$route500/RESEAU_ROUTIER/AERODROME.shp",
    'Route500 - TRONCON_VOIE_FERREE' => "$route500/RESEAU_FERRE/TRONCON_VOIE_FERREE.shp",
    'Route500 - NOEUD_FERRE' => "$route500/RESEAU_FERRE/NOEUD_FERRE.shp",
    'Route500 - TRONCON_HYDROGRAPHIQUE' => "$route500/HABILLAGE/TRONCON_HYDROGRAPHIQUE.shp",
    'Route500 - ZONE_OCCUPATION_SOL' => "$route500/HABILLAGE/ZONE_OCCUPATION_SOL.shp",
    'BDCarthage - COURS_D_EAU' => "$bdcarthage/COURS_D_EAU.shp",
    'geobase 09 - N_PERIMETRE_PPRN_19860006_S_009.TAB' => 
          "$geobases/009/RISQUE/N_ZONAGES_RISQUE_NATUREL/19860006/N_PERIMETRE_PPRN_19860006_S_009.TAB",
    'geobase 30 - N_PERIMETRE_PPRN_S_030.shp' => 
          "$geobases/030/RISQUE/N_ZONAGES_RISQUE_NATUREL/N_PERIMETRE_PPRN_S_030.shp",
    'geobase 41 - N_ZONE_REG_PPRN_20100001_S_041.TAB' => 
          "$geobases/041/RISQUE/N_ZONAGES_RISQUE_NATUREL/20100001/N_ZONE_REG_PPRN_20100001_S_041.TAB",
    'geobase 74 - N_PERIMETRE_PPRN_20110029_S_074.TAB' => 
          "$geobases/074/RISQUE/N_ZONAGES_RISQUE_NATUREL/20110029/N_PERIMETRE_PPRN_20110029_S_074.TAB",
    'geobase 74 - N_PERIMETRE_PPRN_20110029_S_074.shp' => 
          "$geobases/074/RISQUE/N_ZONAGES_RISQUE_NATUREL/20110029/N_PERIMETRE_PPRN_20110029_S_074.shp",
    'geobase 83 - N_ZONE_REG_PPRN_20030010_S_083.TAB' => 
          "$geobases/083/RISQUE/N_ZONAGES_RISQUE_NATUREL/20030010/N_ZONE_REG_PPRN_20030010_S_083.TAB",
  ] as $name => $path)
    echo "<a href='?path=",urlencode($path),"'>$name</a><br>\n";
  die();
}

$ogr = new Ogr2Php($_GET['path'], 'ISO-8859-1');
echo "<pre>info="; print_r($ogr->info());

$nbre = 0;
foreach ($ogr->features() as $feature) {
  echo "feature=$feature\n";
//  echo "feature="; print_r($feature);
  printf("memory_get_usage=%.1f\n",memory_get_usage ()/1024);
//  if ($nbre++ > 20) die("nbre > 20");
}
die("Fin de la lecture des objets\n");
