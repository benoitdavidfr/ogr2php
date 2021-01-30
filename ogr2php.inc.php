<?php
/*PhpDoc:
name:  ogr2php.inc.php
title: ogr2php.inc.php - Itérateur sur les objets OGR
classes:
doc: |
  La classe Ogr2Php permet d'itérer sur les objets d'une source Ogr en renvoyant des Feature.
  Cette lecture s'effectue en générant une commande transformant la source en GeoJSON et en l'interprétant.
journal: |
  28/1/2021:
    - évolutions mineures
  1/8/2018
    - refonte complète
    - remplacement de l'inclusion de featurewkt.inc.php par feature.inc.php
    - chgt de nom en Ogr2Php
    - lecture Ogr au travers de ogr2ogr produisant du GeoJSON
includes: [ feature.inc.php, ogrinfo.inc.php ]
*/
require_once __DIR__.'/ogrinfo.inc.php';
require_once __DIR__.'/feature.inc.php';

/*PhpDoc: classes
name:  Class Ogr2Php
title: Class Ogr2php implements Iterator - Itérateur sur les objets OGR
methods:
doc: |
  Exécution d'un Iterator:
    __construct()
    rewind(): void
    valid(): bool
    current(): Elt
    key(): Key
    next(): void
    -> valid
*/

Class Ogr2php implements Iterator {
  const VERBOSE = false;
  private string $path; // le chemin du fichier
  private $handle=null; // handle sur le pipe en sortie de la comande ogr2ogr
  private ?Feature $cfeature=null; // le feature courant lu par readOneFeature()
  private int $count=0; // compteur du nbre de feature lus à partir de 0, qui utilisé comme clé
  
  /*PhpDoc: methods
  name:  __construct
  title: function __construct(string $path) - création de l'itérateur
  */
  function __construct(string $path) {
    if (self::VERBOSE)
      echo "Ogr2php::__construct(path=$path)<br>\n";
    $this->path = $path;
  }
  
  function info(): array {
    $ogrInfo = new OgrInfo($this->path, '');
    return $ogrInfo->info();
  }
  
  /*PhpDoc: methods
  name:  rewind
  title: function rewind() - Ouvre le pipe et lit l'en-tête
  */
  function rewind() {
    if (self::VERBOSE)
      echo "Ogr2php::rewind()<br>\n";
    $ogrcmde = "ogr2ogr -f GeoJSON -t_srs EPSG:4326 /vsistdout/ ".$this->path;
    if (self::VERBOSE)
      echo "cmde: $ogrcmde<br>\n";
    //die("FIN ligne ".__LINE__."\n");
    $this->handle = popen($ogrcmde, 'r');
    if ($this->handle === false)
      throw new Exception("Erreur sur popen('$ogrcmde, 'r') dans Ogr2php::rewind()");
    //die("FIN ligne ".__LINE__."\n");
    $this->buff = fgets($this->handle);
    if (self::VERBOSE)
      echo "<pre>$this->buff</pre>\n";
    $this->buff = fgets($this->handle);
    if (self::VERBOSE)
      echo "<pre>$this->buff</pre>\n";
    $this->buff = fgets($this->handle);
    if (self::VERBOSE)
      echo "<pre>$this->buff</pre>\n";
    $this->buff = fgets($this->handle);
    if (self::VERBOSE)
      echo "<pre>$this->buff</pre>\n";
    $this->buff = fgets($this->handle);
    if (self::VERBOSE)
      echo "<pre>$this->buff</pre>\n";
    $this->count = 0;
    $this->readOneFeature();
    //die("FIN ligne ".__LINE__."\n");
  }
  
  /*PhpDoc: methods
  name:  readOneFeature
  title: private function readOneFeature() - Lit un Feature dans le fichier et le copie dans $this->cfeature
  doc: |
    En fin de fichier, $this->cfeature = null;
  */
  private function readOneFeature(): void {
    if (self::VERBOSE)
      echo "Ogr2php::readOneFeature()<br>\n";
    $buff = fgets($this->handle);
    //echo "buff=$buff\n";
    if (($buff == false) || (strncmp($buff, '{ "type": "Feature",', 20)<>0)) {
      $this->cfeature = null;
      pclose($this->handle);
      $this->handle = null;
      return;
    }
    $buff = trim($buff);
    $len = strlen($buff);
    if (substr($buff, $len-1, 1)==',')
      $buff = substr($buff, 0, $len-1);
    //echo "buff=$buff\n";
    $this->cfeature = new Feature($buff);
  }
  
  /*PhpDoc: methods
  name:  valid
  title: function valid() - valid ssi feature courant non null
  */
  function valid(): bool {
    if (self::VERBOSE)
      echo "Ogr2php::valid()<br>\n";
    return ($this->cfeature != null);
  }
  
  /*PhpDoc: methods
  name:  current
  title: function current() - renvoie le feature courant
  */
  function current() {
    if (self::VERBOSE)
      echo "Ogr2php::current()<br>\n";
    return $this->cfeature;
  }
  
  /*PhpDoc: methods
  name:  next
  title: function next() - Lit le feature suivant
  */
  function next() {
    if (self::VERBOSE)
      echo "Ogr2php::next()<br>\n";
    $this->readOneFeature();
    $this->count++;
  }
  
/*PhpDoc: methods
name:  key
title: function key() - renvoie la clef courante
*/
  function key() {
    if (self::VERBOSE)
      echo "Ogr2php::key()<br>\n";
    return $this->count;
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


$route500 = '/var/www/html/data/route500/ROUTE500_3-0__SHP_LAMB93_FXX_2020-08-04/ROUTE500'
  .'/1_DONNEES_LIVRAISON_2020-08-00223/R500_3-0_SHP_LAMB93_FXX-ED201';
$layers = [
  'Route500/COMMUNE' => "$route500/ADMINISTRATIF/COMMUNE.shp",
  'Route500/LIMITE_ADMINISTRATIVE' => "$route500/ADMINISTRATIF/LIMITE_ADMINISTRATIVE.shp",
  'Route500/TRONCON_ROUTE' => "$route500/RESEAU_ROUTIER/TRONCON_ROUTE.shp",
  'Route500/NOEUD_ROUTIER' => "$route500/RESEAU_ROUTIER/NOEUD_ROUTIER.shp",
  'Route500/COMMUNICATION_RESTREINTE' => "$route500/RESEAU_ROUTIER/COMMUNICATION_RESTREINTE.shp",
  'Route500/NOEUD_COMMUNE' => "$route500/RESEAU_ROUTIER/NOEUD_COMMUNE.shp",
  'Route500/AERODROME' => "$route500/RESEAU_ROUTIER/AERODROME.shp",
  'Route500/AERODROME' => "$route500/RESEAU_ROUTIER/AERODROME.shp",
  'Route500/TRONCON_VOIE_FERREE' => "$route500/RESEAU_FERRE/TRONCON_VOIE_FERREE.shp",
  'Route500/NOEUD_FERRE' => "$route500/RESEAU_FERRE/NOEUD_FERRE.shp",
  'Route500/TRONCON_HYDROGRAPHIQUE' => "$route500/HABILLAGE/TRONCON_HYDROGRAPHIQUE.shp",
  'Route500/ZONE_OCCUPATION_SOL' => "$route500/HABILLAGE/ZONE_OCCUPATION_SOL.shp",
];

if (php_sapi_name() == 'cli') {
  //echo "argc=$argc; argv="; print_r($argv);
  if ($argc < 2) {
    echo "usage: php $argv[0] {layer}\n";
    echo "où {layer} vaut:\n";
    foreach ($layers as $name => $path)
      echo "  - $name\n";
    die();
  }
  if (!isset($layers[$argv[1]]))
    die("La layer '$argv[1]' n'existe pas\n");
  else
    $path = $layers[$argv[1]];
}
else {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>ogr2php</title></head><body>\n";
  if (!isset($_GET['path'])) {
    foreach ($layers as $name => $path)
      echo "<a href='?path=",urlencode($path),"'>$name</a><br>\n";
    die();
  }
  $path = $_GET['path'];
}

$ogr = new Ogr2Php($path, 'ISO-8859-1');
$nbre = 0;
foreach ($ogr as $id => $feature) {
  echo "feature: $id -> $feature<br>\n";
  //die("FIN ligne ".__LINE__."\n");
  //echo "feature="; print_r($feature);
  //printf("memory_get_usage=%.1f\n",memory_get_usage ()/1024);
  if ($nbre++ >= 3) die("nbre >= 3\n");
}
die("Fin de la lecture des objets\n");
