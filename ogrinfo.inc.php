<?php
/*PhpDoc:
name:  ogrinfo.inc.php
title: ogrinfo.inc.php - Infos sur une source Ogr
classes:
doc: |
  La classe OgrInfopermet d'obtenir diverses informations sur une couche OGR (projection, nbre d'objets, champs, ...)
journal: |
  31/7/2018:
    - nouvelle version
    - cahgt de nom en ogrinfo
  18/12/2016:
    - amélioration pour lire Natural Earth
  17/7/2016:
    - amélioration
  29/5/2016:
    - première version
*/
/*PhpDoc: classes
name:  Class OgrInfo
title: Class OgrInfo
methods:
doc: |
  Exécute une commande orginfo sur un fichier TAB/SHP
  Analyse le listage retourné et le fournit sous forme structurée
*/
Class OgrInfo {
  private $filename;
  private $driver;
  private $layername;
  private $geometry;
  private $featureCount;
  private $extent;
  private $projcs;
  private $encoding='';
  private $fields;
  
/*PhpDoc: methods
name:  __construct
title: function __construct($path, string $encoding='') - création d'un objet Ogr2Php représentant une couche
doc: |
  Prend en paramètre le chemin du fichier SHP ou TAB
  ainsi que l'encodage des champs des objets ('UTF-8' ou 'ISO-8859-1').
   En cas d'erreur, l'objet Ogr2Php est créé avec le message d'erreur.
  En cas de code à améliorer, une exception est générée.
*/
  function __construct(string $path, string $encoding='') {
    if ($encoding)
      $this->encoding = $encoding;
    $basename = basename($path);
    if (($pos=strrpos($basename, '.')) == FALSE) {
      throw new Exception ("BAD BASENAME - impossible de distinguer le nom de base de l'extension");
    }
    $layer = substr($basename, 0, $pos);
    //echo "ogrinfo -so $path $layer\n";
    exec("ogrinfo -so $path $layer", $output, $return);
    if ($return) {
      throw new Exception ("BAD OGRINFO - Execution de ogrinfo incorrecte, code retour = $return");
    }
    $this->error = null;
    $output = implode("\n", $output);
    $pat1 = '[^\[\]]*'; // chaine sans [ ni ]
    $pattern = '!^'
              .'(Had to open data source read-only.)?\s*'
              ."INFO: Open of `([^']*)'\s*"
              ."using driver `([^']*)' successful.\s*"
              .'Layer name: ([a-zA-Z0-9_]*)\s*'
              .'Metadata:\s'
              .'  DBF_DATE_LAST_UPDATE=\d\d\d\d-\d\d-\d\d\s'
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
  
  function __get(string $name) { return $this->$name; }
    
/*PhpDoc: methods
name:  info
title: function info($name=null) - retourne les infos sur la couche ou un champ particulier
*/
  function info() {
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
}


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

require_once __DIR__.'/../geometry/coordsys.inc.php';

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>ogr2php</title></head><body><pre>\n";

$paths= [
  'route500'=> '/var/www/html/data/route500/ROUTE500_2-1__SHP_LAMB93_FXX_2018-04-09/ROUTE500'
    .'/1_DONNEES_LIVRAISON_2018-04-00189/R500_2-1_SHP_LAMB93_FXX-ED181/HABILLAGE/TRONCON_HYDROGRAPHIQUE.shp',
  //'bdcarthage'=> '/home/bdavid/geodata/BDCARTHAGE/'.'HYDROGRAPHIE_SURFACIQUE.SHP',
];

foreach ($paths as $path) {
    echo "$path<br>\n";
    $ogr = new OgrInfo($path);
    //echo "<pre>ogrInfo="; print_r($ogr->info()); echo "\n";
    echo "projcs=",$ogr->projcs,"\n";
    echo "detect=",CoordSys::detect($ogr->projcs),"\n";
    
}