<?php
/*PhpDoc:
name: sqlloader.inc.php
title: sqlloader.inc.php - module V2 générique de chargement d'un produit dans une base MySQL
includes: [ ogr2php.inc.php ]
classes:
doc: |
  La classe SqlLoader met en oeuvre un chargeur SQL en fonction de paramètres du produit à charger
  Utilisation systématique du type Geometry
  
journal: |
  31/7-2/8/2018
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
require_once __DIR__.'/../phplib/mysql.inc.php';
require_once __DIR__.'/ogr2php.inc.php';

/*PhpDoc: classes
name:  Feature
title: class Feature - Définition d'un objet géographique composé d'une liste de champs et d'une géométrie
methods:
doc: |
*/
class SqlLoader {
  static $sql_reserved_words = ['add','ignore'];
  
  // transformation du type Ogr en type SQL
  static function sqltype(string $fieldtype): string {
    if ($fieldtype=='Integer')
      return 'integer';
    if (preg_match('!^String\((\d+)\)$!', $fieldtype, $matches))
      return "varchar($matches[1])";
    if (preg_match('!^Real\((\d+)\.(\d+)\)$!', $fieldtype, $matches))
      return "decimal($matches[1],$matches[2])";
    throw new Exception("dans sqltype(), type '$fieldtype' inconnu");
  }
  
  /*PhpDoc: methods
  name: create_table
  title: static function create_table($info, $tableDef, $suffix, $mysql_database) - instruction SQL create table
  doc: |
    $info correspond à $ogr->info()
    $tableDef correspond à la définition des paramètres pour une table
  */
  static function create_table(OgrInfo $ogr, array $tableDef, string $suffix, string $mysql_database): array {
    $info = $ogr->info();
    if (!isset($info['layername']))
      throw new Exception("ogrinfo incorrect");
    $mysql_database = ($mysql_database ? $mysql_database.'.' : '');
    $table_name = strtolower($info['layername']).$suffix;
    $sqls[0] = "drop table if exists $mysql_database$table_name";
    $sql = "create table $mysql_database$table_name (\n";
    $sqlfields = [];
    if (isset($info['fields']))
      foreach ($info['fields'] as $field) {
  //    print_r($field);
        $name = strtolower($field['name']);
  // cas d'utilisation d'un mot-clé SQL comme nom de champ
        if (in_array($name, self::$sql_reserved_words))
          $name = "col_$name";
        $sqlfields[] = "  $name ".self::sqltype($field['type']).' not null';
      }
    $sql .= implode(",\n",$sqlfields).",\n";
    $sql .= "  geom Geometry not null\n";
    $sql .= ")\n"
         ."ENGINE = MYISAM\n"
         ."DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci";
    $sqls[] = $sql;
    $sqls[] = "create spatial index ${table_name}_geom on $mysql_database$table_name(geom)";
    if (isset($tableDef['indexes']) and $tableDef['indexes']) {
      foreach ($tableDef['indexes'] as $index_fields=>$unique) {
        $index_name = str_replace(',','_',$index_fields);
        $sqls[] = "create ".($unique ? 'unique ':'')
          ."index ${table_name}_${index_name} on $mysql_database$table_name($index_fields);\n";
      }
    }
    return $sqls;
  }
  
  static function insert_into(Ogr2Php $ogr, array $table, string $suffix, string $mysql_database, int $precision, int $nbrmax=20): array {
    $transaction = true; // utilisation des transactions
    //$transaction = false; // utilisation des transactions
    $info = $ogr->info();
    if (!isset($info['layername']))
      throw new Exception("ogrinfo incorrect");
    $mysql_database = ($mysql_database ? $mysql_database.'.' : '');
    $table_name = strtolower($info['layername']).$suffix;
    $fields = [];
    foreach ($info['fields'] as $field) {
      $name = strtolower($field['name']);
      if (in_array($name, self::$sql_reserved_words))
        $name = "col_$name";
      $fields[$field['name']] = $name;
    }
    //Geometry::setParam('precision', $precision);
    $sqls = ["truncate $mysql_database$table_name"];
    if ($transaction)
      $sqls[] = "start transaction";
    $nbre = 0;
    foreach ($ogr as $feature) {
      $nbre++;
      if ($transaction && ($nbre % 1000 == 0))
        $sqls[] = "commit";
      if ($nbrmax && ($nbre > $nbrmax)) {
        if ($transaction)
          $sqls[] .= "commit";
        $sqls[] = "-- Arrêt après $nbrmax\n";
        return $sqls;
      }
      //echo "feature=$feature\n";
      $sql = "insert into $mysql_database$table_name(".implode(',',$fields).",geom) values\n";
      $values = [];
      foreach ($fields as $propname => $field)
        $values[] = '"'.str_replace('"','""',$feature->property($propname)).'"';
      $sql .= "(".implode(',',$values);
      $geom = $feature->geometry()->proj2D()->filter($precision);
      $wkt = $geom->wkt();
      $sql .= ",ST_GeomFromText('$wkt'))";
      if ($geom->isValid())
        $sqls[] = $sql;
      else
        echo "-- invalid $sql\n";
    }
    if ($transaction)
      $sqls[] = "commit";
    return $sqls;
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

if (php_sapi_name()<>'cli') {
  ini_set('max_execution_time', 600);
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>sqlooader2</title></head><body><pre>\n";
}
ini_set('memory_limit', '1280M');

require_once __DIR__.'/../yamldoc/inc.php';
   
Store::setStoreid('docs');
$route500 = new_doc('geodata/route500');

if (php_sapi_name() == 'cli') {
  if ($argc <= 1) {
    //echo "argc=$argc\n";
    //print_r($argv);
    echo "usage: $argv[0] <cmde> [<layer>]\n";
    echo "où <cmde> vaut:\n";
    echo "  yaml\n";
    echo "  ogrinfo\n";
    echo "  create_table\n";
    echo "  insert_into\n";
    echo "  load\n";
    echo "  loadall\n";
    die();
  }
  elseif (($argc == 2) && !in_array($argv[1], ['yaml','loadall'])) {
    echo "usage: $argv[0] $argv[1] <layer>\n";
    echo "où <layer> vaut:\n";
    foreach ($route500->asArray()['layers'] as $lyrname => $layer) {
      if (isset($layer['path']))
        echo "  $lyrname pour $layer[title]\n";
    }
    die();
  }
  else {
    $action = $argv[1];
    $lyrname = isset($argv[2]) ? $argv[2] : null;
  }
}
else { // php_sapi_name() != 'cli'
  if (!isset($_GET['action'])) {
    echo "</pre><h3>Actions possibles:</h3>\n";
    foreach(['yaml','ogrinfo','create_table','insert_into'] as $action)
      echo "<a href='?action=$action'>$action<br>";
    die();
  }
  elseif (($_GET['action'] <> 'yaml') && !isset($_GET['layer'])) {
    echo "</pre><h3>$_GET[action] sur quelle couche ?</h3>\n";
    foreach ($route500->asArray()['layers'] as $lyrname => $layer) {
      if (isset($layer['path']))
        echo "<a href='?action=$_GET[action]&amp;layer=$lyrname'>$layer[title]</a><br>\n";
    }
    die();
  }
  $action = $_GET['action'];
  $lyrname = isset($_GET['layer']) ? $_GET['layer'] : null;
}

switch ($action) {
  case 'yaml':
    echo "route500=",$route500->yaml('');
    die("Fin ligne ".__LINE__."\n");
    
  case 'ogrinfo':
    $tableDef = $route500->asArray()['layers'][$lyrname];
    $path = $route500->asArray()['dbpath'].'/'.$tableDef['path'];
    //echo "path=$path\n";
    $ogr = new OgrInfo($path, 'ISO-8859-1');
    echo "ogrinfo="; print_r($ogr->info());
    die("Fin ligne ".__LINE__."\n");
    
  case 'create_table':
    $tableDef = $route500->asArray()['layers'][$lyrname];
    $path = $route500->asArray()['dbpath'].'/'.$tableDef['path'];
    $ogr = new OgrInfo($path, 'ISO-8859-1');
    foreach (SqlLoader::create_table($ogr, $tableDef, '', '') as $sql)
      echo "$sql;\n";
    die();
  
  case 'insert_into':
    $tableDef = $route500->asArray()['layers'][$lyrname];
    $path = $route500->asArray()['dbpath'].'/'.$tableDef['path'];
    $ogr = new Ogr2Php($path, 'ISO-8859-1');
    foreach (SqlLoader::insert_into($ogr, $tableDef, '', '', $route500->asArray()['precision'], 0) as $sql)
      echo "$sql;\n";
    die();

  case 'load':
    echo "Chargement de $lyrname\n";
    MySql::open(require(__DIR__.'/mysqlparams.inc.php'));
    $tableDef = $route500->asArray()['layers'][$lyrname];
    $path = $route500->asArray()['dbpath'].'/'.$tableDef['path'];
    $ogrInfo = new OgrInfo($path, 'ISO-8859-1');
    foreach (SqlLoader::create_table($ogrInfo, $tableDef, '', '') as $sql)
      MySql::query($sql);
    $ogr2php = new Ogr2Php($path, 'ISO-8859-1');
    foreach (SqlLoader::insert_into($ogr2php, $tableDef, '', '', $route500->asArray()['precision'], 0) as $sql)
      MySql::query($sql);
    die();
  
    case 'loadall':
      foreach ($route500->asArray()['layers'] as $lyrname => $layer) {
        if (!isset($layer['path']))
          continue;
        echo "php $argv[0] load $lyrname\n";
      }
      die();
      
  default:
    die("commande $action inconnue");
}

