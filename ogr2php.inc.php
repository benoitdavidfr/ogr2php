<?php
/*PhpDoc:
name:  ogr2php.inc.php
title: ogr2php.inc.php - Interface OGR en Php
includes: [ ogriter.inc.php ]
classes:
doc: |
  La classe Ogr2Php fournit une interface OGR en Php ;
  elle permet d'obtenir diverses informations sur une couche SHP ou TAB (projection, nbre d'objets, champs, ...)
  et d'accéder aux objets contenus dans la couche.
journal: |
  18/12/2016
  - amélioration pour lire Natural Earth
  17/7/2016
  - amélioration
  29/5/2016
  - première version
*/
require_once 'ogriter.inc.php';

/*PhpDoc: classes
name:  Class Ogr2Php
title: Class Ogr2Php
methods:
doc: |
  Exécute une commande orginfo sur un fichier TAB/SHP
  Analyse le listage retourné et le fournit sous forme structurée
journal: |
  17/7/2016
  - rajout d'un niveau de profondeur pour PROJCS
  29/5/2016
  - première version
*/
Class Ogr2Php {
  private $error;
  private $filename;
  private $driver;
  private $layername;
  private $geometry;
  private $featureCount;
  private $extent;
  private $projcs;
  private $encoding=null;
  private $fields;
  
/*PhpDoc: methods
name:  __construct
title: function __construct($path, $encoding) - création d'un objet Ogr2Php représentant une couche
doc: |
  Prend en paramètre le chemin complet du fichier SHP ou TAB ou un chemin relatif par rapport au répertoire courant
  ainsi que l'encodage des champs des objets ('UTF-8' ou 'ISO-8859-1').
   En cas d'erreur, l'objet Ogr2Php est créé avec le message d'erreur.
  En cas de code à améliorer, une exception est générée.
*/
  function __construct($path, $encoding=null) {
    if ($encoding)
      $this->encoding = $encoding;
    $basename = basename($path);
    if (($pos=strrpos($basename, '.')) == FALSE) {
      $this->error = "BAD BASENAME - impossible de distinguer le nom de base de l'extension";
      return;
    }
    $layer = substr($basename, 0, $pos);
//    $ogrinfo = (getenv('os') ? "c:\\\"Program files\"\\FWTools2.4.7\\bin\\ogrinfo.exe" : 'ogrinfo');
    $ogrinfo = (getenv('os') ? "\\usbgis\\apps\\FWTools\\bin\\ogrinfo.exe" : 'ogrinfo');
//    echo "$ogrinfo -so $path $layer\n";
    exec("$ogrinfo -so $path $layer", $output, $return);
    if ($return) {
      $this->error = "BAD OGRINFO - Execution de ogrinfo incorrecte, code retour = $return";
      return;
    }
    $this->error = null;
    $output = implode("\n", $output);
    $pat1 = '[^\[\]]*'; // chaine sans [ ni ]
    $pattern = '!^'
              .'(Had to open data source read-only.)?\s*'
              ."INFO: Open of `([^']*)'\s*"
              ."using driver `([^']*)' successful.\s*"
              .'Layer name: ([a-zA-Z0-9_]*)\s*'
              .'Geometry: (Unknown \(any\)|3D Point|Point|Line String|3D Line String|Polygon|3D Polygon)\s*'
              .'Feature Count: (\d+)\s*'
              .'Extent: \((-?\d+\.\d+), (-?\d+\.\d+)\) - \((-?\d+\.\d+), (-?\d+\.\d+)\)\s*'
              .'Layer SRS WKT:\s*'
              ."((PROJCS|GEOGCS)\[$pat1(\[$pat1(\[$pat1(\[$pat1(\[$pat1\]$pat1)*\]$pat1)*\]$pat1)*\]$pat1)*\]\s*)"
              .'(([^:]+: (Integer|String|Real|Date) \(\d+\.\d+\)\s*)*)'
              .'$!';
/*
*/
//              .'!';
    if (!preg_match($pattern, $output, $matches)) {
      echo "no match sur:\n<pre>$output</pre>\n";
      throw new Exception("don't match ligne ".__LINE__);
    }
    if ($output = preg_replace($pattern, '', $output)) {
      echo "Reste:\n<pre>$output</pre>\n";
      throw new Exception("Erreur ligne ".__LINE__);
    }
//    echo "<pre>matches="; print_r($matches); echo "</pre>\n";
    $this->filename = $matches[2];
    $this->driver = $matches[3];
    $this->layername = $matches[4];
    $this->geometry = $matches[5];
    $this->featureCount = $matches[6];
    $this->extent = [ 'xmin'=> $matches[7],  'ymin'=> $matches[8],  'xmax'=> $matches[9],  'ymax'=> $matches[10]];
    $this->projcs = $matches[11];
    $fields = $matches[17];
//    echo "fields=$fields\n";
    $this->fields = [];
    $pattern = '!^([^:]+): (Integer|String|Real|Date) \((\d+)\.(\d+)\)\s*!';
    while (preg_match($pattern, $fields, $matches)) {
//      echo "<pre>matches="; print_r($matches); echo "</pre>";
      switch($matches[2]) {
        case 'Integer' :
        case 'Date' :
          $type = $matches[2]; break;
        case 'String' :
          $type = "$matches[2]($matches[3])"; break;
        case 'Real' :
          $type = "$matches[2]($matches[3].$matches[4])"; break;
        default:
          throw new Exception("type $matches[2] non reconnu ligne ".__LINE__);
      }
      $this->fields[] = ['name'=>$matches[1], 'type'=>$type];
      $fields = preg_replace($pattern, '', $fields, 1);
    }
    if ($fields)
//      die("fields=\"$fields\" ligne ".__LINE__."\n");
      throw new Exception("fields=\"$fields\" ligne ".__LINE__."\n");
  }
  
/*PhpDoc: methods
name:  error
title: function error() - indique si une erreur s'est produite et dans ce cas retourne le message correspondant
doc: |
  Retourne null s'il n'y a pas d'erreur.
*/
  function error() { return $this->error; }
  
/*PhpDoc: methods
name:  info
title: function info($name=null) - retourne les infos sur la couche ou un champ particulier
*/
  function info($name=null) {
    if ($this->error)
      return ['error'=>$this->error];
    elseif ($name)
      return $this->$name;
    else
      return [
        'filename' => $this->filename,
        'driver' => $this->driver,
        'layername' => $this->layername,
        'geometry' => $this->geometry,
        'featureCount' => $this->featureCount,
        'extent' => $this->extent,
        'projcs' => $this->projcs,
        'encoding' => $this->encoding,
        'fields' => $this->fields,
      ];
  }
  
/*PhpDoc: methods
name:  features
title: function features() - retourne un itérateur sur les objets de la couche
*/
  function features() {
    return new OgrIter($this);
  }
}


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

require_once 'coordsys.inc.php';

if (getenv('os')) {
  $route500 = 'P:/geodata-met/Route500-ED151/';
  $bdcarthage = 'P:/geodata-met/BDCARTHAGE/';
  $geobases = 'P:/xampp/htdocs/www/geobases/';
} else {
  $route500 = '/home/bdavid/geodata/ROUTE500_2-0__SHP_LAMB93_FXX_2015-08-01/ROUTE500/'
             .'1_DONNEES_LIVRAISON_2015/R500_2-0_SHP_LAMB93_FR-ED151/';
  $bdcarthage = '/home/bdavid/geodata/BDCARTHAGE/';
  $geobases = '/home/bdavid/www/geobases/';
}

foreach ([
//    $route500.'HABILLAGE/TRONCON_HYDROGRAPHIQUE.SHP',
//    $bdcarthage.'HYDROGRAPHIE_SURFACIQUE.SHP',
    $geobases.'009/RISQUE/N_ZONAGES_RISQUE_NATUREL/20140001/N_ZONE_REG_PPRN_20140001_S_009.TAB',
//    $geobases.'041/RISQUE/N_ZONAGES_RISQUE_NATUREL/20100001/N_ZONE_REG_PPRN_20100001_S_041.TAB',
//    '/home/bdavid/www/geobases/083/RISQUE/N_ZONAGES_RISQUE_NATUREL/20030010/N_ZONE_REG_PPRN_20030010_S_083.TAB',
//    'xx.tab',
//    'xx',
  ] as $path) {
    echo "$path<br>\n";
    $ogr = new Ogr2Php($path);
//    echo "<pre>ogrInfo="; print_r($ogr->info()); echo "</pre>\n";
    echo "<pre>projcs=",$ogr->info('projcs'),"</pre>\n";
    echo "<pre>detect=",CoordSys::detect($ogr->info('projcs')),"</pre>\n";
    
}